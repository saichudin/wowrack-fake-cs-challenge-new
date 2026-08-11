<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;

/**
 * Join point: only dispatched once BOTH branches of the earlier
 * Bus::batch([CreateSubnetJob, CreateAclListJob -> CreateAclRuleJob])
 * have finished, since it needs output from both of them.
 */
class AttachAclJob extends PollsFakeCsJob
{
    protected function command(): string
    {
        return 'replaceNetworkACLList';
    }

    protected function step(): FlowStep
    {
        return FlowStep::AttachAcl;
    }

    protected function params(Deployment $deployment): array
    {
        return [
            'aclid' => $deployment->acl_list_id,
            'networkid' => $deployment->subnet_id,
        ];
    }

    protected function onSuccess(Deployment $deployment, array $result): void
    {
        // Nothing to save — attaching doesn't produce a new resource id.
    }
}
