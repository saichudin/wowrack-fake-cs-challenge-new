<?php

namespace App\Services\Deployment;

use App\Enums\DeploymentStatus;
use App\Exceptions\FakeCs\FakeCsCallTimedOutException;
use App\Jobs\MarkDeploymentSuccessJob;
use App\Jobs\RollbackDeploymentJob;
use App\Jobs\Steps\AttachAclJob;
use App\Jobs\Steps\CreateAclListJob;
use App\Jobs\Steps\CreateSubnetJob;
use App\Jobs\Steps\CreateVpcJob;
use App\Jobs\Steps\DeployVmJob;
use App\Jobs\Steps\EnableStaticNatJob;
use App\Jobs\Steps\ListPublicIpJob;
use App\Models\Deployment;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Builds and dispatches the Bus::chain()/Bus::batch() structure for one
 * deployment, shaped directly by the dependency graph documented on
 * App\Enums\FlowStep:
 *
 *   create_vpc
 *     -> [create_subnet | create_acl_list -> create_acl_rule] PARALLEL
 *     -> attach_acl (join point)
 *     -> [deploy_vm | list_public_ip] PARALLEL   (only if public_ip = true)
 *     -> enable_static_nat (join point)
 *
 * This is the entry point both for a brand new deployment
 * (DeploymentController) and for every retry after a timeout
 * (RollbackDeploymentJob::finish()).
 */
class DeploymentPipeline
{
    /** Total attempts allowed (not retries — 3 means "try up to 3 times
     *  total") before a timeout makes us give up for good. */
    public const MAX_ATTEMPTS = 3;

    /** Backoff base: attempt 2 waits ~10s, attempt 3 waits ~20s (doubles
     *  each retry) before hitting fake-cs again — if a timeout was caused
     *  by fake-cs struggling, retrying instantly just hits the same
     *  problem again. */
    private const RETRY_BASE_DELAY_SECONDS = 10;

    /** Random extra on top of the backoff, so deployments that all timed
     *  out around the same moment don't all retry at the exact same
     *  instant and pile back onto fake-cs together. */
    private const RETRY_JITTER_MAX_SECONDS = 5;

    public static function dispatch(Deployment $deployment): void
    {
        $delay = self::retryDelay($deployment->attempt);

        // A fresh attempt (no delay) is marked "processing" right away.
        // A retry keeps whatever status the caller already set
        // (RollbackDeploymentJob::finish() sets "retrying") for the
        // whole backoff wait + this attempt, instead of jumping to
        // "processing" before any work has actually started.
        if ($delay === 0) {
            $deployment->update(['status' => DeploymentStatus::Processing]);
        }

        $steps = [
            new CreateVpcJob($deployment->id),

            // create_subnet and create_acl_list both only need vpc_id, not
            // each other, so they run in the same batch. create_acl_rule
            // gets added to this same batch dynamically once create_acl_list
            // succeeds (see CreateAclListJob::onSuccess()) — a plain
            // ->chain() here would NOT work, because jobs chained onto a
            // batch member aren't counted by the batch itself, so the batch
            // would finish before create_acl_rule ever ran.
            Bus::batch([
                new CreateSubnetJob($deployment->id),
                new CreateAclListJob($deployment->id),
            ]),

            // Join point: needs subnet_id AND acl_list_id/rule, i.e. both
            // branches of the batch above to be finished.
            new AttachAclJob($deployment->id),
        ];

        if ($deployment->public_ip) {
            // deploy_vm and list_public_ip don't depend on each other at
            // all (list_public_ip doesn't depend on anything), so they run
            // together instead of waiting for the VM first.
            $steps[] = Bus::batch([
                new DeployVmJob($deployment->id),
                new ListPublicIpJob($deployment->id),
            ]);
            $steps[] = new EnableStaticNatJob($deployment->id); // join point: vm_id + public_ip_id
        } else {
            $steps[] = new DeployVmJob($deployment->id);
        }

        $steps[] = new MarkDeploymentSuccessJob($deployment->id);

        Bus::chain($steps)
            ->delay($delay)
            ->catch(fn (Throwable $e) => self::handleFailure($deployment->id, $e))
            ->dispatch();
    }

    /** 0 for the first attempt (no reason to wait), exponential backoff + jitter after that. */
    private static function retryDelay(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }

        $backoff = self::RETRY_BASE_DELAY_SECONDS * (2 ** ($attempt - 2));

        return $backoff + random_int(0, self::RETRY_JITTER_MAX_SECONDS);
    }

    private static function handleFailure(string $deploymentId, Throwable $e): void
    {
        $deployment = Deployment::findOrFail($deploymentId);

        // "Failed Job" (jobstatus=2) -> rollback and stop.
        // "Timeout" -> rollback, then retry the whole flow (up to MAX_ATTEMPTS).
        $isTimeout = $e instanceof FakeCsCallTimedOutException;
        $shouldRetry = $isTimeout && $deployment->attempt < self::MAX_ATTEMPTS;

        // Only a timeout that has actually exhausted every attempt counts
        // as "giving up" — a plain Failed Job never retries in the first
        // place, so that message would be misleading here.
        $failureReason = ($isTimeout && ! $shouldRetry)
            ? 'Gave up after '.self::MAX_ATTEMPTS.' attempts: '.$e->getMessage()
            : $e->getMessage();

        $deployment->update([
            'status' => DeploymentStatus::RollingBack,
            'rollback_phase' => 'destroy_vm',
            'rollback_job_id' => null,
            'rollback_poll_attempts' => 0,
        ]);

        RollbackDeploymentJob::dispatch($deploymentId, $deployment->attempt, $failureReason, $shouldRetry);
    }
}
