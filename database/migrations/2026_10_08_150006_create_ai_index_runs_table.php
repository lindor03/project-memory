<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_index_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scan_scope', 32)->index('ai_runs_scope');
            $table->string('git_revision')->nullable();
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('files_updated')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedInteger('deleted_nodes')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('errors')->nullable();
            $table->string('status', 32)->index('ai_runs_status');
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_index_runs');
    }
};
