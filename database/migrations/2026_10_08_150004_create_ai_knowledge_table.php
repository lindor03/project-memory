<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('knowledge_key', 191)->unique('ai_knowledge_key_unique');
            $table->string('kind', 64)->index('ai_knowledge_kind');
            $table->string('title');
            $table->text('body');
            $table->string('state', 32)->index('ai_knowledge_state');
            $table->json('source_refs')->nullable();
            $table->json('source_hashes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge');
    }
};
