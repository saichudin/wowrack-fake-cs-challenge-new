<?php

namespace App\Support;

use App\Enums\FlowStep;

/**
 * Wraps the optional `simulate` block a tester can send when creating a
 * deployment, e.g.:
 *
 *   "simulate": { "step": "create_acl_rule", "result": 2 }   // force a failure
 *   "simulate": { "step": "deploy_vm", "timeout": 35 }        // force a timeout
 *
 * `result`, `delay` and `timeout` are exactly the query params fake-cs
 * itself understands (confirmed by hitting the real API), so we don't
 * translate them — we just forward whichever ones were sent, and only for
 * the ONE step named here. Every other step in the flow runs untouched.
 */
final readonly class SimulateOptions
{
    public function __construct(
        private ?string $step = null,
        private ?int $result = null,
        private ?int $delay = null,
        private ?int $timeout = null,
    ) {}

    public static function fromArray(?array $data): self
    {
        return new self(
            step: $data['step'] ?? null,
            result: $data['result'] ?? null,
            delay: $data['delay'] ?? null,
            timeout: $data['timeout'] ?? null,
        );
    }

    /**
     * Extra fake-cs query params to send for $step, or [] if $step isn't
     * the one being targeted (i.e. run normally).
     *
     * @return array<string, int>
     */
    public function paramsFor(FlowStep $step): array
    {
        if ($this->step !== $step->value) {
            return [];
        }

        return array_filter([
            'result' => $this->result,
            'delay' => $this->delay,
            'timeout' => $this->timeout,
        ], fn (?int $value) => $value !== null);
    }
}
