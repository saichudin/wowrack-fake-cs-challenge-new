<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'public_ip',
        'simulate',
        'status',
        'attempt',
        'vpc_id',
        'subnet_id',
        'acl_list_id',
        'vm_id',
        'public_ip_id',
        'failure_reason',
        'rollback_warnings',
        'rollback_phase',
        'rollback_job_id',
        'rollback_poll_attempts',
    ];

    protected function casts(): array
    {
        return [
            'public_ip' => 'boolean',
            'simulate' => 'array',
            'status' => DeploymentStatus::class,
            'rollback_warnings' => 'array',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class);
    }
}
