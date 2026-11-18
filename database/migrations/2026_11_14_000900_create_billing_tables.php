<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 40)->primary(); // e.g. plan_yearly
            $table->string('name', 60);
            $table->string('interval', 20); // month | six_months | year
            $table->unsignedSmallInteger('interval_months');
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->unsignedTinyInteger('savings_percent')->nullable();
            $table->boolean('highlighted')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('paystack_plan_code', 60)->nullable()->unique();
            $table->string('monnify_plan_code', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('plan_id', 40);
            $table->string('status', 12)->index(); // active | cancelled | past_due | expired
            $table->string('provider', 12);
            $table->string('provider_subscription_code', 80)->nullable()->index();
            $table->string('provider_customer_code', 80)->nullable()->index();
            $table->text('provider_email_token')->nullable(); // encrypted
            $table->text('authorization')->nullable();        // encrypted JSON (reusable card token, brand, last4)
            $table->timestamp('started_at');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end')->index();
            $table->timestamp('next_billing_date')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('access_until')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('pending_plan_id', 40)->nullable();
            $table->timestamp('pending_plan_effective_at')->nullable();
            $table->timestamp('gateway_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('pending_plan_id')->references('id')->on('plans')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_id', 40)->nullable();
            $table->string('reference', 100)->unique();
            $table->string('provider', 12)->index();
            $table->string('purpose', 20)->default('subscription'); // subscription | renewal
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 12)->index(); // pending | success | failed | abandoned | refunded
            $table->string('channel', 30)->nullable();
            $table->string('provider_transaction_id', 100)->nullable()->index();
            $table->string('gateway_response', 255)->nullable();
            $table->text('authorization_url')->nullable();
            $table->string('app_callback_url', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 12);
            $table->string('event_key', 191); // idempotency key
            $table->string('type', 80)->nullable();
            $table->string('reference', 100)->nullable()->index();
            $table->jsonb('payload');
            $table->string('status', 12)->default('received'); // received | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
