<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('deployment_id')->constrained()->cascadeOnDelete();

            // One of the App\Enums\FlowStep values, e.g. "create_vpc".
            $table->string('step');
            $table->string('status')->default('pending');
            $table->text('message')->nullable();

            // Polling state for async steps (App\Jobs\Steps\PollsFakeCsJob).
            // This has to live in the database, NOT as a property on the job
            // object: release() re-queues the job's ORIGINAL payload as-is,
            // it does not re-serialize whatever the job mutated in memory —
            // so a plain PHP property would silently reset to null on every
            // single release() cycle.
            $table->string('fake_cs_job_id')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);

            $table->timestamps();

            // A deployment only ever has one row per step — updateOrCreate()
            // in ProcessDeploymentJob relies on this to overwrite in place.
            $table->unique(['deployment_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_steps');
    }
};
