<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            // Every request is treated as a brand new "user" (see requirement
            // point 4), so a random uuid is all the identity we need — no
            // users table involved.
            $table->uuid('id')->primary();

            $table->boolean('public_ip')->default(false);

            // The optional { step, result, delay, timeout } block a tester
            // sends to force one specific step to fail or hang.
            $table->json('simulate')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempt')->default(1);

            // Resource ids created so far. This is the single source of
            // truth both for reporting progress (GET /api/deployments/{id})
            // and for knowing exactly what RollbackService needs to clean up.
            $table->string('vpc_id')->nullable();
            $table->string('subnet_id')->nullable();
            $table->string('acl_list_id')->nullable();
            $table->string('vm_id')->nullable();
            $table->string('public_ip_id')->nullable();

            $table->text('failure_reason')->nullable();

            // Polling state for App\Jobs\RollbackDeploymentJob — same reason
            // as deployment_steps.fake_cs_job_id: release() doesn't persist
            // mutated job properties, so this has to live in the database.
            $table->string('rollback_phase')->nullable();
            $table->string('rollback_job_id')->nullable();
            $table->unsignedSmallInteger('rollback_poll_attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
