<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remote configuration and operational toggles (non-secret values only).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 120);
            $table->string('body', 500);
            $table->string('route', 255)->nullable();
            $table->string('type', 40)->default('announcement');
            $table->string('audience', 12)->default('all'); // all | platform | users | version
            $table->jsonb('filters')->nullable(); // {platform, user_ids[], app_version, locale}
            $table->string('status', 12)->default('draft')->index(); // draft | scheduled | sending | sent | failed | cancelled
            $table->string('source', 12)->default('admin'); // admin | system
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('invalid_token_count')->default(0);
            $table->text('error')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Delivery log for transactional notifications (email, WhatsApp, push).
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 12)->index(); // mail | whatsapp | fcm
            $table->string('type', 60)->index();
            $table->string('destination', 120)->nullable(); // masked
            $table->string('status', 10)->index(); // sent | failed | skipped
            $table->string('provider_message_id', 191)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 10)->default('user'); // user | system | gateway
            $table->string('action', 80)->index();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->jsonb('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        // Outbound integration calls (no bodies or secrets are stored).
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 20)->index();
            $table->string('operation', 60);
            $table->string('method', 8);
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('correlation_id', 100)->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('app_settings');
    }
};
