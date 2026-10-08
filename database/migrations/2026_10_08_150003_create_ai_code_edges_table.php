<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_code_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_node_id')->constrained('ai_code_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('ai_code_nodes')->cascadeOnDelete();
            $table->string('relationship', 64)->index('ai_edges_relationship');
            $table->decimal('confidence', 3, 2)->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_node_id', 'target_node_id', 'relationship'],
                'ai_edges_unique_rel'
            );
            $table->index('target_node_id', 'ai_edges_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_edges');
    }
};
