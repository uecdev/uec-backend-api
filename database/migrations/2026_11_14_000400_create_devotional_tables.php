<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devotional_editions', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->string('title', 120);
            $table->string('theme', 200)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->boolean('is_premium')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('bundle_size')->default(0);
            $table->timestamps();
        });

        Schema::create('devotional_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedSmallInteger('edition_year');
            $table->date('date')->unique();
            $table->string('title', 200);
            $table->string('passage', 120);
            $table->text('memory_verse_text')->nullable();
            $table->string('memory_verse_ref', 120)->nullable();
            $table->text('body');
            $table->text('declaration')->nullable();
            $table->text('prayer')->nullable();
            $table->timestamps();

            $table->foreign('edition_year')->references('year')->on('devotional_editions')->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['edition_year', 'date']);
        });

        Schema::create('devotional_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->timestamp('read_at');

            $table->unique(['user_id', 'entry_date']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devotional_reads');
        Schema::dropIfExists('devotional_entries');
        Schema::dropIfExists('devotional_editions');
    }
};
