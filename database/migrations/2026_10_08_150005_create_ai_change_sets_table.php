<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_change_sets', function (Blueprint $table) {
            $table->id();
            $table->text('intent');
            $table->string('git_commit')->nullable()->index('ai_changes_commit');
            $table->json('changed_files')->nullable();
            $table->json('changed_symbols')->nullable();
            $table->text('expected_impact')->nullable();
            $table->text('actual_impact')->nullable();
            $table->string('validation_outcome')->nullable();
            $table->text('lessons')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_change_sets');
    }
};
