<?php

namespace App\Support;

use App\Enums\FlowStep;

/**
 * Wraps the optional `simulate` block a tester can send when creating a
 * deployment, e.g.:
 *
 *   "simulate": { "step": "create_acl_rule", "result": 2 }   // force a failure
 *   "simulate": { "step": "deploy_vm", "timeout": 35 }        // force a timeout
 *   "simulate": { "step": "attach_acl", "timeout": 35, "only_attempt": 1 }
 *
 * `result`, `delay` and `timeout` are exactly the query params fake-cs
 * itself understands (confirmed by hitting the real API), so we don't
 * translate them — we just forward whichever ones were sent, and only for
 * the ONE step named here. Every other step in the flow runs untouched.
 *
 * `only_attempt` is ours, not fake-cs's — without it, a simulated timeout
 * would keep triggering on every retry too (the same `simulate` block is
 * reused as-is on every attempt), so there'd be no way to demo "times out
 * once, then the retry succeeds". Set it to limit which attempt number the
 * simulation actually applies to; every other attempt runs untouched.
 */
final readonly class SimulateOptions
{
    public function __construct(
        private ?string $step = null,
        private ?int $result = null,
        private ?int $delay = null,
        private ?int $timeout = null,
        private ?int $onlyAttempt = null,
    ) {}

    public static function fromArray(?array $data): self
    {
        return new self(
            step: $data['step'] ?? null,
            result: $data['result'] ?? null,
            delay: $data['delay'] ?? null,
            timeout: $data['timeout'] ?? null,
            onlyAttempt: $data['only_attempt'] ?? null,
        );
    }

    /**
     * Extra fake-cs query params to send for $step on this $attempt, or []
     * if $step isn't the one being targeted or `only_attempt` excludes
     * this attempt (i.e. run normally).
     *
     * @return array<string, int>
     */
    public function paramsFor(FlowStep $step, int $attempt): array
    {
        if ($this->step !== $step->value) {
            return [];
        }

        if ($this->onlyAttempt !== null && $this->onlyAttempt !== $attempt) {
            return [];
        }

        return array_filter([
            'result' => $this->result,
            'delay' => $this->delay,
            'timeout' => $this->timeout,
        ], fn (?int $value) => $value !== null);
    }
}
