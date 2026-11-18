<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bible_versions', function (Blueprint $table) {
            $table->string('id', 16)->primary(); // e.g. KJV
            $table->string('name', 120);
            $table->string('abbreviation', 16);
            $table->string('licence_note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('bible_verses', function (Blueprint $table) {
            $table->string('version_id', 16);
            $table->char('book_id', 3); // USFM code
            $table->unsignedSmallInteger('chapter');
            $table->unsignedSmallInteger('verse');
            $table->text('text');

            $table->primary(['version_id', 'book_id', 'chapter', 'verse']);
            $table->foreign('version_id')->references('id')->on('bible_versions')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bible_verses');
        Schema::dropIfExists('bible_versions');
    }
};
