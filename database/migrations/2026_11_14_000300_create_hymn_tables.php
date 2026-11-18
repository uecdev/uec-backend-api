<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hymn_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('hymns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedInteger('number')->unique();
            $table->string('title', 200);
            $table->string('first_line', 255);
            $table->string('author', 200)->nullable();
            $table->string('composer', 200)->nullable();
            $table->string('tune', 120)->nullable();
            $table->string('meter', 60)->nullable();
            $table->string('key', 30)->nullable();
            $table->string('tempo', 60)->nullable();
            $table->text('about')->nullable();
            $table->jsonb('scripture_refs')->default('[]');
            $table->jsonb('tags')->default('[]');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('hymn_category_hymn', function (Blueprint $table) {
            $table->foreignUlid('hymn_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hymn_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['hymn_id', 'hymn_category_id']);
        });

        Schema::create('hymn_stanzas', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('hymn_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 10); // verse | chorus | refrain | bridge
            $table->unsignedSmallInteger('number')->nullable();
            $table->jsonb('lines');
            $table->timestamps();

            $table->unique(['hymn_id', 'position']);
        });

        Schema::create('hymn_solfa', function (Blueprint $table) {
            $table->foreignUlid('hymn_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('format', 5); // text | image
            $table->string('key', 30)->nullable();
            $table->string('time_signature', 10)->nullable();
            $table->jsonb('sections')->nullable(); // [{label, lines[]}]
            $table->string('image_path', 255)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });

        Schema::create('hymn_audio', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('hymn_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('storage_path', 255);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hymn_audio');
        Schema::dropIfExists('hymn_solfa');
        Schema::dropIfExists('hymn_stanzas');
        Schema::dropIfExists('hymn_category_hymn');
        Schema::dropIfExists('hymns');
        Schema::dropIfExists('hymn_categories');
    }
};
