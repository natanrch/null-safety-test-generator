<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('authors');
    }
};
