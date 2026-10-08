<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_extraction_cache', function (Blueprint $table) {
            $table->char('cache_key', 64)->primary();
            $table->char('content_hash', 64)->index();
            $table->char('analyzer_fingerprint', 64);
            $table->json('payload');
            $table->timestamp('last_used_at')->index();
        });

        Schema::table('ai_code_edges', function (Blueprint $table) {
            $table->char('declaration_path_hash', 64)->nullable()->index('ai_edges_declaration');
            $table->char('reference_hash', 64)->nullable();
            // MySQL may use the original unique index for the source FK.
            // Supply a replacement before dropping it.
            $table->index('source_node_id', 'ai_edges_source');
            $table->dropUnique('ai_edges_unique_rel');
            $table->unique(['source_node_id', 'target_node_id', 'relationship', 'declaration_path_hash', 'reference_hash'], 'ai_edges_unique_rel');
        });
        Schema::table('ai_code_nodes', function (Blueprint $table) {
            $table->index(['module_id', 'node_type'], 'ai_nodes_module_type');
        });
    }

    public function down(): void
    {
        // Multiple declarations can now share a relationship. Refuse a lossy
        // rollback; deployments should retain this additive schema or back up
        // and rebuild the disposable graph, preserving ai_knowledge separately.
        if (DB::table('ai_code_edges')
            ->select('source_node_id', 'target_node_id', 'relationship')
            ->groupBy('source_node_id', 'target_node_id', 'relationship')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back edge provenance with duplicate declarations. Preserve knowledge and rebuild the graph first.');
        }
        Schema::table('ai_code_edges', function (Blueprint $table) {
            $table->dropUnique('ai_edges_unique_rel');
            $table->dropIndex('ai_edges_declaration');
            $table->dropColumn('declaration_path_hash');
            $table->dropColumn('reference_hash');
            $table->unique(['source_node_id', 'target_node_id', 'relationship'], 'ai_edges_unique_rel');
            $table->dropIndex('ai_edges_source');
        });
        Schema::table('ai_code_nodes', fn (Blueprint $table) => $table->dropIndex('ai_nodes_module_type'));
        Schema::dropIfExists('ai_extraction_cache');
    }
};
