<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\SubscriptionNotification;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Iso;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->configurePaystack();
        $this->configureMonnify();
    }

    public function test_change_preview_and_scheduled_change_without_charge(): void
    {
        Http::fake(); // no gateway call should charge anything
        $user = $this->member();
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => 'plan_monthly', 'provider' => 'monnify']);
        $auth = $this->tokenFor($user);

        $this->getJson('/api/v1/me/subscription/change-preview?plan_id=plan_yearly', $auth)->assertOk()
            ->assertJsonPath('data.plan.id', 'plan_yearly')
            ->assertJsonPath('data.current_plan.id', 'plan_monthly')
            ->assertJsonPath('data.amount', 1990000)
            ->assertJsonPath('data.effective_date', $subscription->current_period_end->timezone('Africa/Lagos')->toDateString());

        $this->getJson('/api/v1/me/subscription/change-preview?plan_id=plan_monthly', $auth)->assertStatus(409)->assertJsonPath('code', 'SAME_PLAN');

        $this->postJson('/api/v1/me/subscription/change', ['plan_id' => 'plan_yearly'], $auth)->assertOk()
            ->assertJsonPath('data.plan.id', 'plan_monthly')
            ->assertJsonPath('data.pending_plan.id', 'plan_yearly')
            ->assertJsonPath('data.status', 'active');

        $this->postJson('/api/v1/me/subscription/change', ['plan_id' => 'plan_yearly'], $auth)->assertStatus(409)->assertJsonPath('code', 'SAME_PLAN');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_cancel_keeps_access_until_period_end_then_expires(): void
    {
        Http::fake(['api.paystack.co/subscription/disable' => Http::response(['status' => true])]);
        $user = $this->member();
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'provider_subscription_code' => 'SUB_1', 'provider_email_token' => 'tok']);
        $auth = $this->tokenFor($user);

        $this->postJson('/api/v1/me/subscription/cancel', ['reason' => 'Too expensive'], $auth)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.next_billing_date', null)
            ->assertJsonPath('data.access_until', Iso::ts($subscription->current_period_end));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/subscription/disable'));

        // Idempotent.
        $this->postJson('/api/v1/me/subscription/cancel', [], $auth)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/me', $auth)->assertJsonPath('data.is_premium', true);

        $this->travelTo($subscription->current_period_end->copy()->addMinute());
        app(SubscriptionService::class)->enforceLifecycle();

        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
        $this->getJson('/api/v1/me', $this->tokenFor($user))->assertJsonPath('data.is_premium', false);
    }

    public function test_changing_plan_while_cancelled_reactivates_auto_renew(): void
    {
        Http::fake();
        $user = $this->member();
        Subscription::factory()->create([
            'user_id' => $user->id, 'provider' => 'monnify', 'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(), 'access_until' => now()->addDays(20),
        ]);

        $this->postJson('/api/v1/me/subscription/change', ['plan_id' => 'plan_monthly'], $this->tokenFor($user))->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.pending_plan', null);
    }

    public function test_cancel_survives_gateway_outage(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 503)]);
        $user = $this->member();
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'provider_subscription_code' => 'SUB_1', 'provider_email_token' => 'tok']);

        $this->postJson('/api/v1/me/subscription/cancel', [], $this->tokenFor($user))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($subscription->fresh()->gateway_synced_at, 'Gateway sync is retried by a queued job');
    }

    public function test_monnify_renewal_is_charged_with_the_stored_card_token_and_applies_plan_change(): void
    {
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response(['requestSuccessful' => true, 'responseBody' => ['accessToken' => 't', 'expiresIn' => 3600]]),
            'sandbox.monnify.com/api/v1/merchant/cards/charge-card-token' => fn ($r) => Http::response(['requestSuccessful' => true, 'responseBody' => [
                'paymentReference' => $r['paymentReference'], 'transactionReference' => 'MNFY|R1', 'paymentStatus' => 'PAID',
                'amountPaid' => $r['amount'], 'currencyCode' => 'NGN',
            ]]),
        ]);
        $subscription = Subscription::factory()->create([
            'provider' => 'monnify', 'plan_id' => 'plan_monthly', 'pending_plan_id' => 'plan_yearly',
            'authorization' => ['token' => 'MNFY_TOKEN', 'reusable' => true],
            'current_period_end' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(PaymentService::class)->chargeDueRenewals());
        $this->assertSame(0, app(PaymentService::class)->chargeDueRenewals(), 'One attempt per period');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'charge-card-token') && $r['cardToken'] === 'MNFY_TOKEN' && $r['amount'] == 19900);
        $subscription->refresh();
        $this->assertSame('plan_yearly', $subscription->plan_id);
        $this->assertNull($subscription->pending_plan_id);
        $this->assertTrue($subscription->current_period_end->isAfter(now()->addMonths(11)));
        $this->assertDatabaseHas('payments', ['subscription_id' => $subscription->id, 'purpose' => 'renewal', 'status' => 'success', 'amount_kobo' => 1990000]);
    }

    public function test_failed_renewal_enters_grace_then_expires(): void
    {
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response(['requestSuccessful' => true, 'responseBody' => ['accessToken' => 't', 'expiresIn' => 3600]]),
            'sandbox.monnify.com/api/v1/merchant/cards/charge-card-token' => Http::response(['requestSuccessful' => false, 'responseMessage' => 'Insufficient funds'], 400),
        ]);
        $subscription = Subscription::factory()->create([
            'provider' => 'monnify', 'authorization' => ['token' => 'MNFY_TOKEN', 'reusable' => true], 'current_period_end' => now()->subMinute(),
        ]);

        app(PaymentService::class)->chargeDueRenewals();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertTrue($subscription->grantsAccess(), 'Access continues during grace');
        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);

        $this->travel(config('uec.subscriptions.grace_days') + 1)->days();
        app(SubscriptionService::class)->enforceLifecycle();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
        Notification::assertSentTo($subscription->user, SubscriptionNotification::class, fn ($n) => $n->event === 'expired');
    }

    public function test_active_subscription_without_renewal_becomes_past_due(): void
    {
        $subscription = Subscription::factory()->create(['provider_subscription_code' => 'SUB_1', 'current_period_end' => now()->subHours(25)]);

        $result = app(SubscriptionService::class)->enforceLifecycle();

        $this->assertSame(1, $result['past_due']);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
    }

    public function test_paystack_plan_change_creates_gateway_subscription_at_period_end(): void
    {
        Http::fake([
            'api.paystack.co/subscription/disable' => Http::response(['status' => true]),
            'api.paystack.co/subscription' => Http::response(['status' => true, 'data' => ['subscription_code' => 'SUB_new', 'email_token' => 'tok_new']]),
        ]);
        Plan::query()->whereKey('plan_yearly')->update(['paystack_plan_code' => 'PLN_yearly']);
        $user = $this->member();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id, 'provider_subscription_code' => 'SUB_old', 'provider_email_token' => 'tok_old',
        ]);

        $this->postJson('/api/v1/me/subscription/change', ['plan_id' => 'plan_yearly'], $this->tokenFor($user))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscription/disable') && $r['code'] === 'SUB_old');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscription') && $r['plan'] === 'PLN_yearly' && $r['authorization'] === 'AUTH_test'
            && $r['start_date'] === $subscription->current_period_end->toIso8601String());
        $this->assertSame('SUB_new', $subscription->fresh()->provider_subscription_code);
    }
}
