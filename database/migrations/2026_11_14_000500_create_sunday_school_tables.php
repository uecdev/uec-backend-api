<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ss_manuals', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->string('title', 120);
            $table->string('theme', 200)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->boolean('is_premium')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('bundle_size')->default(0);
            $table->timestamps();
        });

        Schema::create('ss_lessons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedSmallInteger('manual_year');
            $table->unsignedSmallInteger('number');
            $table->string('title', 200);
            $table->string('passage', 120);
            $table->text('memory_verse_text')->nullable();
            $table->string('memory_verse_ref', 120)->nullable();
            $table->text('aim')->nullable();
            $table->text('introduction')->nullable();
            $table->jsonb('outline')->default('[]');            // [{heading, body}]
            $table->jsonb('applications')->default('[]');       // [rich text]
            $table->text('conclusion')->nullable();
            $table->jsonb('revision_questions')->default('[]'); // [string]
            $table->timestamps();

            $table->foreign('manual_year')->references('year')->on('ss_manuals')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['manual_year', 'number']);
        });

        Schema::create('ss_plan_weeks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('manual_year');
            $table->date('date'); // a Sunday
            $table->unsignedTinyInteger('week'); // week of month 1–5
            $table->foreignUlid('lesson_id')->nullable()->constrained('ss_lessons')->nullOnDelete();
            $table->string('description', 255);
            $table->timestamps();

            $table->foreign('manual_year')->references('year')->on('ss_manuals')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['manual_year', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_plan_weeks');
        Schema::dropIfExists('ss_lessons');
        Schema::dropIfExists('ss_manuals');
    }
};
