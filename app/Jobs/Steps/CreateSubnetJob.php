<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;
use App\Services\FakeCs\FakeCsClient;

/**
 * Only depends on vpc_id, same as CreateAclListJob — DeploymentPipeline
 * runs the two together in one Bus::batch().
 */
class CreateSubnetJob extends SyncStepJob
{
    protected function step(): FlowStep
    {
        return FlowStep::CreateSubnet;
    }

    protected function run(Deployment $deployment, FakeCsClient $client): void
    {
        $body = $client->trigger('createNetwork', array_merge([
            'vpcid' => $deployment->vpc_id,
            'name' => "subnet-{$deployment->id}",
            'gateway' => '10.0.1.1',
            'netmask' => '255.255.255.0',
        ], $this->simulate()->paramsFor($this->step())));

        $deployment->update(['subnet_id' => $body['network']['id']]);
    }
}
