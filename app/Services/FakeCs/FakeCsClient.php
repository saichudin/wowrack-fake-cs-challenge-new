<?php

namespace App\Services\FakeCs;

use App\Exceptions\FakeCs\FakeCsCallFailedException;
use App\Exceptions\FakeCs\FakeCsCallTimedOutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the fake-cs mock cloud API.
 *
 * This client is deliberately "dumb": it never loops and never sleeps. A
 * job that needs to wait for an async fake-cs job polls it by calling
 * checkJob() ONCE per execution and re-queuing itself with release() if
 * it's not done yet — see App\Jobs\Steps\PollsFakeCsJob. That way a worker
 * is never tied up blocking on a single deployment; it's free to pick up
 * other jobs while this one "waits" in the queue instead of in memory.
 */
class FakeCsClient
{
    /** PHP's curl default is 30s — we mirror that so our timeout behaves
     *  exactly like the requirement describes ("PHP curl will timeout after
     *  30 seconds"). */
    private const HTTP_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * Fire one fake-cs command, exactly once.
     *
     * For sync commands (Async = NO in the command matrix) this already IS
     * the final result. For async commands it only contains a `jobid` —
     * the caller is responsible for polling it later via checkJob().
     *
     * @throws FakeCsCallFailedException
     * @throws FakeCsCallTimedOutException
     */
    public function trigger(string $command, array $params = []): array
    {
        $body = $this->envelope($this->send($command, $params), $command);

        if (isset($body['errorcode'])) {
            throw new FakeCsCallFailedException(
                "{$command} failed: ".($body['errortext'] ?? 'unknown error')
            );
        }

        return $body;
    }

    /**
     * Check an async job's status ONE time — no loop, no sleep. Whoever
     * calls this decides what to do if it's still processing (typically:
     * $this->release($seconds) and try again on the next execution).
     *
     * @throws FakeCsCallTimedOutException if the check call itself hangs
     */
    public function checkJob(string $jobId, string $command): FakeCsJobCheck
    {
        $result = $this->envelope(
            $this->send('queryAsyncJobResult', ['jobid' => $jobId]),
            'queryAsyncJobResult',
        );

        return match ($result['jobstatus'] ?? 0) {
            1 => FakeCsJobCheck::success($result['jobresult'] ?? []),
            2 => FakeCsJobCheck::failed("{$command} failed (jobid={$jobId})"),
            default => FakeCsJobCheck::processing(),
        };
    }

    /**
     * @throws FakeCsCallTimedOutException when fake-cs never responds at all
     */
    private function send(string $command, array $params): Response
    {
        try {
            return Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($this->baseUrl, array_merge(['command' => $command], $params));
        } catch (ConnectionException $e) {
            throw new FakeCsCallTimedOutException(
                "{$command} timed out (no response from fake-cs)",
                previous: $e,
            );
        }
    }

    /** fake-cs wraps every reply as {"<command, lowercase>response": {...}}. */
    private function envelope(Response $response, string $command): array
    {
        return $response->json(strtolower($command).'response') ?? [];
    }
}
