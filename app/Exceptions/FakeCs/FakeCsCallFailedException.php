<?php

namespace App\Exceptions\FakeCs;

use RuntimeException;

/**
 * fake-cs explicitly rejected the command: either a sync call answered with
 * an `errorcode`, or an async job finished with `jobstatus = 2`.
 *
 * This maps to the requirement's "Failed Job" use case: roll back whatever
 * was already created and STOP — no automatic retry for this one.
 */
class FakeCsCallFailedException extends RuntimeException
{
}
