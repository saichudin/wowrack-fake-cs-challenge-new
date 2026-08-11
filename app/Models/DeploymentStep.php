<?php

namespace App\Models;

use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentStep extends Model
{
    protected $fillable = [
        'deployment_id',
        'step',
        'status',
        'message',
        'fake_cs_job_id',
        'poll_attempts',
    ];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
