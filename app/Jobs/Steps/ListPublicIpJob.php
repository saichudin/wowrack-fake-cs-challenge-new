<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Exceptions\FakeCs\FakeCsCallFailedException;
use App\Models\Deployment;
use App\Services\FakeCs\FakeCsClient;

/**
 * Doesn't depend on anything at all (not even the VPC), so
 * DeploymentPipeline runs this in the same Bus::batch() as DeployVmJob
 * instead of waiting for the VM first.
 */
class ListPublicIpJob extends SyncStepJob
{
    protected function step(): FlowStep
    {
        return FlowStep::ListPublicIp;
    }

    protected function run(Deployment $deployment, FakeCsClient $client): void
    {
        $body = $client->trigger('listPublicIpAddresses', $this->simulate()->paramsFor($this->step()));

        // Only ids in "Free" state can be attached to a VM.
        $free = collect($body['publicipaddress'] ?? [])->firstWhere('state', 'Free');

        if (! $free) {
            throw new FakeCsCallFailedException('No free public IP address available');
        }

        $deployment->update(['public_ip_id' => $free['id']]);
    }
}
