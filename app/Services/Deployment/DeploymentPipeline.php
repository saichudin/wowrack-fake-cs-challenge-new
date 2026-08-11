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

    public static function dispatch(Deployment $deployment): void
    {
        $deployment->update(['status' => DeploymentStatus::Processing]);

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
            ->catch(fn (Throwable $e) => self::handleFailure($deployment->id, $e))
            ->dispatch();
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
