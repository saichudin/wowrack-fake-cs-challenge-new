<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Support\SimulateOptions;

/**
 * Shared by every step job (async or sync) so GET /api/deployments/{id}
 * always has an up-to-date status per step, regardless of which job class
 * is currently working on it.
 */
trait TracksDeploymentStep
{
    protected function deployment(): Deployment
    {
        return Deployment::findOrFail($this->deploymentId);
    }

    protected function simulate(): SimulateOptions
    {
        return SimulateOptions::fromArray($this->deployment()->simulate);
    }

    protected function markStep(Deployment $deployment, FlowStep $step, StepStatus $status, ?string $message = null): void
    {
        DeploymentStep::updateOrCreate(
            ['deployment_id' => $deployment->id, 'step' => $step->value],
            ['status' => $status, 'message' => $message],
        );
    }

    /**
     * The row a PollsFakeCsJob reads/writes its `fake_cs_job_id` and
     * `poll_attempts` on — this is the "explicit state" that survives
     * release() cycles, since job object properties do not.
     */
    protected function stepRow(Deployment $deployment, FlowStep $step): DeploymentStep
    {
        return DeploymentStep::firstOrCreate(
            ['deployment_id' => $deployment->id, 'step' => $step->value],
            ['status' => StepStatus::Pending],
        );
    }
}
