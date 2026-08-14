<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Enums\StepStatus;
use App\Exceptions\FakeCs\FakeCsCallFailedException;
use App\Exceptions\FakeCs\FakeCsCallTimedOutException;
use App\Models\Deployment;
use App\Services\FakeCs\FakeCsClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Base for one ASYNC fake-cs command (Async = YES in the command matrix —
 * the ones that hand back a `jobid`).
 *
 * Instead of blocking a worker with a sleep() loop until the job finishes,
 * this class fires the command once, then re-queues ITSELF with release()
 * to check again later. Every execution of handle() below is a brand new
 * pickup by (possibly a different) worker — the worker is completely free
 * to work on other deployments in between checks.
 *
 * IMPORTANT: release() does NOT re-serialize whatever this object mutated
 * in memory — it just re-queues the job's ORIGINAL payload (from when it
 * was first dispatched) with a new available_at time. So `fake_cs_job_id`
 * and `poll_attempts` are read from and written to the DeploymentStep row
 * (see TracksDeploymentStep::stepRow()), never kept as plain job
 * properties — a property would silently reset to its initial value on
 * every single release() cycle.
 */
abstract class PollsFakeCsJob implements ShouldQueue
{
    use Batchable, Queueable, TracksDeploymentStep;

    private const POLL_DELAY_SECONDS = 2;

    /** ~90s total budget per step, matching the old blocking client's safety net. */
    private const MAX_POLL_ATTEMPTS = 45;

    // release() still counts as an "attempt" in Laravel's eyes, so this
    // has to comfortably cover MAX_POLL_ATTEMPTS worth of release() cycles
    // (~45) plus the very first trigger — otherwise Laravel kills the job
    // with MaxAttemptsExceededException while it's still legitimately
    // polling. A REAL failure still ends the job immediately regardless of
    // this number, because handle() below calls $this->fail() explicitly
    // instead of relying on retries running out.
    public int $tries = 100;

    public function __construct(public readonly string $deploymentId)
    {
    }

    /** The fake-cs command this step calls, e.g. "createVpc". */
    abstract protected function command(): string;

    /** Extra params for the trigger call (merged with simulate() automatically). */
    abstract protected function params(Deployment $deployment): array;

    /** Which FlowStep this job represents, for status tracking. */
    abstract protected function step(): FlowStep;

    /** Save whatever this step produced (e.g. the new vpc_id) onto the deployment. */
    abstract protected function onSuccess(Deployment $deployment, array $result): void;

    public function handle(FakeCsClient $client): void
    {
        try {
            $this->attempt($client);
        } catch (Throwable $e) {
            // $this->fail() ends the job PERMANENTLY right now, regardless
            // of how much of the $tries budget above is left — that budget
            // exists only to give release() room to keep polling, not to
            // let Laravel silently retry a real failure on its own before
            // our chain-level catch() gets a chance to run.
            $this->fail($e);
        }
    }

    private function attempt(FakeCsClient $client): void
    {
        $deployment = $this->deployment();
        $stepRow = $this->stepRow($deployment, $this->step());

        if ($stepRow->fake_cs_job_id === null) {
            $stepRow->update(['status' => StepStatus::Processing]);

            $body = $client->trigger($this->command(), array_merge(
                $this->params($deployment),
                $this->simulate()->paramsFor($this->step(), $deployment->attempt),
            ));

            $stepRow->update(['fake_cs_job_id' => $body['jobid'], 'poll_attempts' => 0]);
            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if ($stepRow->poll_attempts + 1 > self::MAX_POLL_ATTEMPTS) {
            throw new FakeCsCallTimedOutException(
                "{$this->command()} did not finish within budget (jobid={$stepRow->fake_cs_job_id})"
            );
        }

        $check = $client->checkJob($stepRow->fake_cs_job_id, $this->command());

        if ($check->processing) {
            $stepRow->increment('poll_attempts');
            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if (! $check->succeeded) {
            throw new FakeCsCallFailedException($check->error);
        }

        $this->onSuccess($deployment, $check->result);

        // Clear `message` too, not just flip the status — otherwise a step
        // that failed on an earlier attempt (e.g. via `only_attempt`) but
        // then succeeds on a retry would keep showing its old failure
        // message even though it's now `success`.
        $stepRow->update(['status' => StepStatus::Success, 'fake_cs_job_id' => null, 'message' => null]);
    }

    /**
     * Laravel calls this once the job fails for good (via $this->fail()
     * above) — the single place that records the failure, no matter which
     * of the three throw sites in attempt() caused it.
     */
    public function failed(Throwable $e): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if ($deployment) {
            $this->markStep($deployment, $this->step(), StepStatus::Failed, $e->getMessage());
        }
    }
}
