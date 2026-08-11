<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;

class DeployVmJob extends PollsFakeCsJob
{
    /** fake-cs is a mock — it doesn't validate these against a real
     *  catalog, it just needs *some* non-empty value. */
    private const SERVICE_OFFERING_ID = 'small';

    private const TEMPLATE_ID = 'ubuntu-22-04';

    protected function command(): string
    {
        return 'deployVirtualMachine';
    }

    protected function step(): FlowStep
    {
        return FlowStep::DeployVm;
    }

    protected function params(Deployment $deployment): array
    {
        return [
            'networkids' => $deployment->subnet_id,
            'serviceofferingid' => self::SERVICE_OFFERING_ID,
            'templateid' => self::TEMPLATE_ID,
        ];
    }

    protected function onSuccess(Deployment $deployment, array $result): void
    {
        $deployment->update(['vm_id' => $result['virtualmachine']['id']]);
    }
}
