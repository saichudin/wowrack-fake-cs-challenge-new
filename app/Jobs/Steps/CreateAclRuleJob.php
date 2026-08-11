<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;

class CreateAclRuleJob extends PollsFakeCsJob
{
    protected function command(): string
    {
        return 'createNetworkACL';
    }

    protected function step(): FlowStep
    {
        return FlowStep::CreateAclRule;
    }

    protected function params(Deployment $deployment): array
    {
        return [
            'aclid' => $deployment->acl_list_id,
            'protocol' => 'TCP',
        ];
    }

    protected function onSuccess(Deployment $deployment, array $result): void
    {
        // Nothing to save — ACL rules don't have an id we need for rollback,
        // they're auto-deleted along with their parent ACL list.
    }
}
