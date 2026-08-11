<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Always the LAST link in DeploymentPipeline's Bus::chain(). A plain chain
 * has no "the whole thing succeeded" callback (that's a batch-only
 * concept) — the simplest way to know every step finished is to make
 * finishing the final job in the chain BE that signal.
 */
class MarkDeploymentSuccessJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $deploymentId)
    {
    }

    public function handle(): void
    {
        Deployment::whereKey($this->deploymentId)->update(['status' => DeploymentStatus::Success]);
    }
}
