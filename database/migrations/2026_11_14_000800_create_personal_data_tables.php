<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('favouritable_type', 10); // hymn | lesson
            $table->ulid('favouritable_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'favouritable_type', 'favouritable_id']);
            $table->index(['favouritable_type', 'favouritable_id']);
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary(); // client-generated UUID v4
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('date')->nullable();
            $table->jsonb('hymn_ids')->default('[]');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable(); // last-write-wins clock supplied by the client

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
        Schema::dropIfExists('favourites');
    }
};
