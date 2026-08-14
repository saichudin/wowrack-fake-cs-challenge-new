<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;
use App\Services\FakeCs\FakeCsClient;

/**
 * Join point: needs vm_id (from DeployVmJob) AND public_ip_id (from
 * ListPublicIpJob) — both run in parallel just before this.
 */
class EnableStaticNatJob extends SyncStepJob
{
    protected function step(): FlowStep
    {
        return FlowStep::EnableStaticNat;
    }

    protected function run(Deployment $deployment, FakeCsClient $client): void
    {
        $client->trigger('enableStaticNat', array_merge([
            'virtualmachineid' => $deployment->vm_id,
            'ipaddressid' => $deployment->public_ip_id,
            'networkid' => $deployment->subnet_id,
        ], $this->simulate()->paramsFor($this->step(), $deployment->attempt)));
    }
}
