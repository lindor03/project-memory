<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique('ai_modules_key_unique');
            $table->string('name');
            $table->string('root_path', 1024)->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_modules');
    }
};
