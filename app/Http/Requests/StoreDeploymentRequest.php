<?php

namespace App\Http\Requests;

use App\Enums\FlowStep;
use App\Services\Deployment\DeploymentPipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Every request is a brand new "user" (requirement point 4) — no
        // login, no ownership check needed for this demo.
        return true;
    }

    public function rules(): array
    {
        return [
            'public_ip' => ['required', 'boolean'],

            // Optional: force ONE step in the flow to fail or hang, to
            // demonstrate the rollback/retry behaviour on demand.
            'simulate' => ['sometimes', 'array'],
            'simulate.step' => ['required_with:simulate', Rule::enum(FlowStep::class)],
            'simulate.result' => ['nullable', 'integer', Rule::in([1, 2])],
            'simulate.delay' => ['nullable', 'integer', 'min:1', 'max:120'],
            'simulate.timeout' => ['nullable', 'integer', 'min:1', 'max:120'],

            // Optional: restrict the simulation above to only one specific
            // attempt (e.g. 1) instead of it re-triggering on every retry —
            // needed to demo "times out once, then the retry succeeds".
            'simulate.only_attempt' => ['nullable', 'integer', 'min:1', 'max:'.DeploymentPipeline::MAX_ATTEMPTS],
        ];
    }
}
