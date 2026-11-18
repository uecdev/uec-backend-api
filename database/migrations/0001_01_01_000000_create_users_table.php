<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 80)->nullable();
            // E.164 phone and lower-cased email; both optional (phone-only, email-only or Google accounts).
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 191)->nullable()->unique();
            $table->string('password')->nullable(); // null for Google-only accounts
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('google_sub', 64)->nullable()->unique();
            $table->string('avatar_url', 500)->nullable();
            $table->string('avatar_path', 255)->nullable(); // Cloudinary public_id or storage path
            $table->string('locale', 5)->default('en');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_platform', 10)->nullable();
            $table->string('last_app_version', 20)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Emails are stored lower-cased by the application; this guards against case-variant duplicates.
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email)) WHERE email IS NOT NULL');
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
