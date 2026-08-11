<?php

namespace App\Exceptions\FakeCs;

use RuntimeException;

/**
 * fake-cs never answered in time — either the HTTP call itself hung (a real
 * cURL connection timeout) or an async job was still stuck at
 * `jobstatus = 0` after our own polling budget ran out.
 *
 * This maps to the requirement's "Timeout" use case: roll back whatever was
 * already created, then retry the WHOLE flow from the beginning.
 */
class FakeCsCallTimedOutException extends RuntimeException
{
}
