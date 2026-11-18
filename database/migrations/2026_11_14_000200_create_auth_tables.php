<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rotating, hashed refresh tokens with family-based reuse detection (backend-handoff §D.1).
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->ulid('replaced_by_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // FCM device registrations (§H.1).
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 10); // ios | android | web
            $table->string('app_version', 20)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'app_version']);
        });

        // One row per user per active day; drives DAU/WAU/MAU and retention metrics.
        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('platform', 10)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->unsignedInteger('requests')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['date', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_days');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('refresh_tokens');
    }
};
