<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Services\FakeCs\FakeCsClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Base for one SYNC fake-cs command (Async = NO in the command matrix —
 * the result is already final in the very first response, no jobid to
 * poll). These jobs never call release(): they run once and they're done,
 * just like an ordinary Laravel job.
 */
abstract class SyncStepJob implements ShouldQueue
{
    use Batchable, Queueable, TracksDeploymentStep;

    // A real failure must propagate straight to the chain's catch() so
    // OUR retry-the-whole-flow logic runs, not Laravel's per-job retry.
    public int $tries = 1;

    public function __construct(public readonly string $deploymentId)
    {
    }

    abstract protected function step(): FlowStep;

    abstract protected function run(Deployment $deployment, FakeCsClient $client): void;

    public function handle(FakeCsClient $client): void
    {
        $deployment = $this->deployment();

        $this->markStep($deployment, $this->step(), StepStatus::Processing);
        $this->run($deployment, $client);
        $this->markStep($deployment, $this->step(), StepStatus::Success);
    }

    public function failed(Throwable $e): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if ($deployment) {
            $this->markStep($deployment, $this->step(), StepStatus::Failed, $e->getMessage());
        }
    }
}
