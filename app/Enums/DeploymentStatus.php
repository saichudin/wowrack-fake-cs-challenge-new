<?php

namespace App\Enums;

/**
 * Overall state of one deployment (= one "user" request), shown to whoever
 * polls GET /api/deployments/{id}.
 *
 * pending      -> row created, the queue worker hasn't picked up the job yet
 * processing   -> steps are being executed (see DeploymentStep for detail)
 * rolling_back -> a step failed/timed out, we're cleaning up created resources
 * retrying     -> cleanup finished after a timeout, running the whole flow again
 * success      -> every step finished, VM (and public IP) ready
 * failed       -> stopped for good, either an explicit failure or too many timeouts
 */
enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RollingBack = 'rolling_back';
    case Retrying = 'retrying';
    case Success = 'success';
    case Failed = 'failed';
}
