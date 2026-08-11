<?php

namespace App\Services\FakeCs;

/**
 * Result of ONE (non-looping) queryAsyncJobResult check — used by jobs that
 * poll via release() instead of blocking in a sleep() loop. A job calls
 * checkJob() once per execution and decides what to do next based on this.
 */
final readonly class FakeCsJobCheck
{
    private function __construct(
        public bool $processing,
        public bool $succeeded,
        public array $result,
        public ?string $error,
    ) {
    }

    public static function processing(): self
    {
        return new self(processing: true, succeeded: false, result: [], error: null);
    }

    public static function success(array $result): self
    {
        return new self(processing: false, succeeded: true, result: $result, error: null);
    }

    public static function failed(string $error): self
    {
        return new self(processing: false, succeeded: false, result: [], error: $error);
    }
}
