<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;

/**
 * create_acl_rule needs acl_list_id, which only exists once THIS job
 * succeeds — so instead of a Bus::chain([...]) attached in
 * DeploymentPipeline (chained jobs are NOT automatically counted by the
 * Bus::batch() this job runs in, so the batch would finish without ever
 * waiting for create_acl_rule), CreateAclRuleJob is added to this job's
 * OWN batch from inside onSuccess() via $this->batch()->add(). That's the
 * officially supported way to say "this batch isn't really done until this
 * follow-up job finishes too".
 */
class CreateAclListJob extends PollsFakeCsJob
{
    protected function command(): string
    {
        return 'createNetworkACLList';
    }

    protected function step(): FlowStep
    {
        return FlowStep::CreateAclList;
    }

    protected function params(Deployment $deployment): array
    {
        return [
            'vpcid' => $deployment->vpc_id,
            'name' => "acl-{$deployment->id}",
        ];
    }

    protected function onSuccess(Deployment $deployment, array $result): void
    {
        $deployment->update(['acl_list_id' => $result['networkacllist']['id']]);

        $this->batch()?->add([new CreateAclRuleJob($this->deploymentId)]);
    }
}
