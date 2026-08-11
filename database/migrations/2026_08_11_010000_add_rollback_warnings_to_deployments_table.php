<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            // Rollback is best-effort (see RollbackDeploymentJob::skipToNextPhase()):
            // a delete call that fails or times out is logged and skipped so
            // cleanup always finishes, but that means a resource *could* be
            // left behind at fake-cs even though its id gets nulled here.
            // This column surfaces those skips through the API too, not just
            // the server log — a JSON list of {attempt, command, reason, at}.
            $table->json('rollback_warnings')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('rollback_warnings');
        });
    }
};
