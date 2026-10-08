<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 64)->index('ai_agent_runs_operation');
            $table->string('agent')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('reported_input_tokens')->nullable();
            $table->unsignedInteger('reported_output_tokens')->nullable();
            $table->unsignedInteger('reported_cached_tokens')->nullable();
            $table->unsignedInteger('retrieved_context_size')->default(0);
            $table->unsignedInteger('estimated_input_tokens')->nullable();
            $table->unsignedInteger('estimated_output_tokens')->nullable();
            $table->string('token_basis', 32)->default('estimated');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost', 12, 6)->nullable();
            $table->string('cost_currency', 8)->nullable();
            $table->boolean('cost_is_estimated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_runs');
    }
};
