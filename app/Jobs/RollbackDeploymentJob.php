<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPipeline;
use App\Services\FakeCs\FakeCsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Saga's compensating actions, running as ONE self-advancing job
 * instead of three chained ones.
 *
 * Why one job walking three phases, and not
 * Bus::chain([DestroyVirtualMachineJob, DeleteNetworkJob, DeleteVpcJob])
 * like the forward flow? Because rollback needs "best effort, always
 * attempt every remaining phase even if an earlier one failed" — and a
 * Bus::chain stops at the first failed link by design. Keeping the
 * skip-on-error logic in one place (advancePhase() below) is simpler than
 * fighting that behaviour with three separate catch() handlers.
 *
 * It's still fully non-blocking: every wait for a fake-cs job to finish
 * happens via release(), exactly like PollsFakeCsJob — and for the same
 * reason that job persists its polling state on the DeploymentStep row
 * instead of as a plain property, this job persists `rollback_phase` /
 * `rollback_job_id` / `rollback_poll_attempts` on the Deployment row.
 * release() re-queues the ORIGINAL dispatch payload, not whatever this
 * object mutated in memory, so a plain property would reset every cycle.
 *
 * fake-cs auto-deletes ACL lists/rules and static NAT mappings whenever
 * their parent VPC/subnet/VM disappears (confirmed by hitting the real
 * API), so only the VM, subnet and VPC ever need an explicit delete call —
 * always in that order, because fake-cs refuses to delete a subnet that
 * still has an active VM attached to it (also confirmed by testing).
 */
class RollbackDeploymentJob implements ShouldQueue
{
    use Queueable;

    private const POLL_DELAY_SECONDS = 2;

    private const MAX_POLL_ATTEMPTS = 45;

    private const PHASES = ['destroy_vm', 'delete_network', 'delete_vpc', 'done'];

    // Same reasoning as PollsFakeCsJob: release() counts as an "attempt",
    // so this needs to comfortably cover polling all three phases
    // (~45 release() cycles each) without Laravel cutting it short.
    public int $tries = 300;

    public function __construct(
        public readonly string $deploymentId,
        public readonly int $failedAttempt,
        public readonly string $failureReason,
        public readonly bool $shouldRetry,
    ) {
    }

    public function handle(FakeCsClient $client): void
    {
        $deployment = Deployment::findOrFail($this->deploymentId);

        $this->skipPhasesWithNothingToClean($deployment);

        if ($deployment->rollback_phase === 'done') {
            $this->finish($deployment);

            return;
        }

        [$command, $resourceId] = $this->currentCommand($deployment);

        if ($deployment->rollback_job_id === null) {
            try {
                $jobId = $client->trigger($command, ['id' => $resourceId])['jobid'];
            } catch (Throwable $e) {
                $this->skipToNextPhase($deployment, $command, $e->getMessage());

                return;
            }

            $deployment->update(['rollback_job_id' => $jobId, 'rollback_poll_attempts' => 0]);
            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if ($deployment->rollback_poll_attempts + 1 > self::MAX_POLL_ATTEMPTS) {
            $this->skipToNextPhase($deployment, $command, 'gave up waiting for it to finish');

            return;
        }

        $check = $client->checkJob($deployment->rollback_job_id, $command);

        if ($check->processing) {
            $deployment->increment('rollback_poll_attempts');
            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if (! $check->succeeded) {
            $this->skipToNextPhase($deployment, $command, $check->error ?? 'unknown error');

            return;
        }

        $this->advancePhase($deployment);
        $this->release(0);
    }

    /** Jump straight to whichever phase has a resource id to clean up. */
    private function skipPhasesWithNothingToClean(Deployment $deployment): void
    {
        $hasNothingToClean = [
            'destroy_vm' => ! $deployment->vm_id,
            'delete_network' => ! $deployment->subnet_id,
            'delete_vpc' => ! $deployment->vpc_id,
        ];

        $phase = $deployment->rollback_phase ?? self::PHASES[0];

        while (($hasNothingToClean[$phase] ?? false) === true) {
            $phase = self::PHASES[array_search($phase, self::PHASES, true) + 1];
        }

        if ($phase !== $deployment->rollback_phase) {
            $deployment->update(['rollback_phase' => $phase]);
        }
    }

    /** @return array{0: string, 1: string} [fake-cs command, resource id] for the current phase */
    private function currentCommand(Deployment $deployment): array
    {
        return match ($deployment->rollback_phase) {
            'destroy_vm' => ['destroyVirtualMachine', $deployment->vm_id],
            'delete_network' => ['deleteNetwork', $deployment->subnet_id],
            'delete_vpc' => ['deleteVpc', $deployment->vpc_id],
        };
    }

    /**
     * Cleanup must never get stuck on one bad call — log it and keep going.
     * Also recorded on the deployment itself (not just the server log) so
     * "this resource might not actually be gone" is visible through the API
     * too, via GET /api/deployments/{id}.
     */
    private function skipToNextPhase(Deployment $deployment, string $command, string $reason): void
    {
        Log::warning("Rollback step {$command} did not complete cleanly, continuing anyway", [
            'deployment_id' => $this->deploymentId,
            'reason' => $reason,
        ]);

        $deployment->update([
            'rollback_warnings' => [
                ...($deployment->rollback_warnings ?? []),
                [
                    'attempt' => $this->failedAttempt,
                    'command' => $command,
                    'reason' => $reason,
                    'at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $this->advancePhase($deployment);
        $this->release(0);
    }

    private function advancePhase(Deployment $deployment): void
    {
        $next = self::PHASES[array_search($deployment->rollback_phase, self::PHASES, true) + 1];

        $deployment->update([
            'rollback_phase' => $next,
            'rollback_job_id' => null,
            'rollback_poll_attempts' => 0,
        ]);
    }

    private function finish(Deployment $deployment): void
    {
        $deployment->update([
            'vpc_id' => null,
            'subnet_id' => null,
            'acl_list_id' => null,
            'vm_id' => null,
            'public_ip_id' => null,
            'rollback_phase' => null,
            'rollback_job_id' => null,
            'rollback_poll_attempts' => 0,
        ]);

        if (! $this->shouldRetry) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'failure_reason' => $this->failureReason,
            ]);

            return;
        }

        $deployment->update([
            'status' => DeploymentStatus::Retrying,
            'attempt' => $this->failedAttempt + 1,
        ]);

        DeploymentPipeline::dispatch($deployment->fresh());
    }
}
