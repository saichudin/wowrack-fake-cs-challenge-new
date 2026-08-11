<?php

namespace App\Jobs\Steps;

use App\Enums\FlowStep;
use App\Models\Deployment;

class CreateVpcJob extends PollsFakeCsJob
{
    protected function command(): string
    {
        return 'createVpc';
    }

    protected function step(): FlowStep
    {
        return FlowStep::CreateVpc;
    }

    protected function params(Deployment $deployment): array
    {
        return [
            'name' => "vpc-{$deployment->id}",
            'cidr' => '10.0.0.0/16',
        ];
    }

    protected function onSuccess(Deployment $deployment, array $result): void
    {
        $deployment->update(['vpc_id' => $result['vpc']['id']]);
    }
}
