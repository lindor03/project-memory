<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_code_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->nullable()->constrained('ai_modules')->nullOnDelete();
            $table->string('node_key', 250)->unique('ai_nodes_key_unique');
            $table->string('file_path', 1024)->nullable();
            $table->char('path_hash', 64)->nullable()->index('ai_nodes_path_hash');
            $table->string('symbol_name', 250)->nullable()->index('ai_nodes_symbol');
            $table->string('node_type', 64)->index('ai_nodes_type');
            $table->char('content_hash', 64)->nullable()->index('ai_nodes_hash');
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_nodes');
    }
};
