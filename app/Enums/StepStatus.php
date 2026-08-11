<?php

namespace App\Enums;

/**
 * State of a single step inside a deployment (one row per FlowStep).
 */
enum StepStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
}
