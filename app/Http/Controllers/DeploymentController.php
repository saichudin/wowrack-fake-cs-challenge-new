<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Http\Requests\StoreDeploymentRequest;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPipeline;
use Illuminate\Http\JsonResponse;

class DeploymentController extends Controller
{
    /**
     * Simulate a new user requesting "deploy a VM". The deployment is
     * created immediately and handed to a queue job so this endpoint can
     * respond right away (202 Accepted) instead of blocking on however
     * long the whole saga takes — poll show() for progress.
     */
    public function store(StoreDeploymentRequest $request): JsonResponse
    {
        $deployment = Deployment::create([
            'public_ip' => $request->boolean('public_ip'),
            'simulate' => $request->input('simulate'),
            'status' => DeploymentStatus::Pending,
        ]);

        DeploymentPipeline::dispatch($deployment);

        return response()->json([
            'deployment_id' => $deployment->id,
            'status' => $deployment->status,
            'status_url' => route('deployments.show', $deployment),
        ], 202);
    }

    public function show(Deployment $deployment): JsonResponse
    {
        return response()->json([
            'deployment_id' => $deployment->id,
            'public_ip' => $deployment->public_ip,
            'status' => $deployment->status,
            'attempt' => $deployment->attempt,
            'resources' => [
                'vpc_id' => $deployment->vpc_id,
                'subnet_id' => $deployment->subnet_id,
                'acl_list_id' => $deployment->acl_list_id,
                'vm_id' => $deployment->vm_id,
                'public_ip_id' => $deployment->public_ip_id,
            ],
            'failure_reason' => $deployment->failure_reason,
            // Resources that a best-effort rollback couldn't confirm were
            // actually deleted — see RollbackDeploymentJob::skipToNextPhase().
            'rollback_warnings' => $deployment->rollback_warnings ?? [],
            'steps' => $deployment->steps()
                ->orderBy('id')
                ->get(['step', 'status', 'message', 'updated_at']),
        ]);
    }
}
