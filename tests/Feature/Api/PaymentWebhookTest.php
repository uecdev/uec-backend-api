<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->configurePaystack();
        $this->configureMonnify();
    }

    private function postPaystack(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? $this->paystackSignature($body),
        ], $body);
    }

    private function chargeSuccess(string $reference, int $amount, array $extra = []): array
    {
        return ['event' => 'charge.success', 'data' => array_merge([
            'id' => random_int(1000, 999999), 'status' => 'success', 'reference' => $reference, 'amount' => $amount, 'currency' => 'NGN',
            'channel' => 'card', 'paid_at' => now()->toIso8601String(),
            'authorization' => ['authorization_code' => 'AUTH_x', 'brand' => 'visa', 'last4' => '4081', 'reusable' => true],
            'customer' => ['customer_code' => 'CUS_abc', 'email' => 'm@example.com'],
        ], $extra)];
    }

    public function test_signed_charge_success_activates_subscription_once(): void
    {
        $payment = Payment::factory()->create(['plan_id' => 'plan_monthly', 'amount_kobo' => 250000]);
        $payload = $this->chargeSuccess($payment->reference, 250000);
        $payload['data']['id'] = 555;

        $this->postPaystack($payload)->assertOk()->assertJson(['status' => 'queued']);
        $this->postPaystack($payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame('CUS_abc', Subscription::query()->value('provider_customer_code'));
        $this->assertSame('processed', WebhookEvent::query()->sole()->status);
    }

    public function test_invalid_signature_is_rejected_and_logged(): void
    {
        $payment = Payment::factory()->create();
        $this->postPaystack($this->chargeSuccess($payment->reference, 250000), 'bad-signature')->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.webhook_signature_invalid']);
    }

    public function test_tampered_amount_in_webhook_is_rejected(): void
    {
        $payment = Payment::factory()->create(['amount_kobo' => 1990000, 'plan_id' => 'plan_yearly']);
        $this->postPaystack($this->chargeSuccess($payment->reference, 250000))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_subscription_lifecycle_events(): void
    {
        $subscription = Subscription::factory()->create(['provider_customer_code' => 'CUS_abc', 'provider_subscription_code' => null]);

        $this->postPaystack(['event' => 'subscription.create', 'data' => [
            'subscription_code' => 'SUB_vsyqdmlzble3uii', 'email_token' => 'd7gofp6yppn3qz7', 'next_payment_date' => now()->addMonth()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_abc'], 'plan' => ['plan_code' => 'PLN_x'],
        ]])->assertOk();
        $subscription->refresh();
        $this->assertSame('SUB_vsyqdmlzble3uii', $subscription->provider_subscription_code);
        $this->assertSame('d7gofp6yppn3qz7', $subscription->provider_email_token);

        // Renewal charge initiated by Paystack (reference unknown to us).
        $periodEnd = $subscription->current_period_end;
        $this->postPaystack($this->chargeSuccess('T_paystack_generated_1', 250000, ['plan' => ['plan_code' => 'PLN_x']]))->assertOk();
        $subscription->refresh();
        $this->assertTrue($subscription->current_period_end->gt($periodEnd));
        $this->assertDatabaseHas('payments', ['reference' => 'T_paystack_generated_1', 'purpose' => 'renewal', 'status' => 'success', 'subscription_id' => $subscription->id]);

        // Renewal failure → past due.
        $this->postPaystack(['event' => 'invoice.payment_failed', 'data' => ['invoice_code' => 'INV_1', 'subscription' => ['subscription_code' => 'SUB_vsyqdmlzble3uii']]])->assertOk();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        Notification::assertSentTo($subscription->user, SubscriptionNotification::class, fn ($n) => $n->event === 'renewal_failed');

        // Disabled at Paystack → cancelled with access until period end (applies to active subscriptions).
        $subscription->refresh()->forceFill(['status' => SubscriptionStatus::Active, 'past_due_at' => null])->save();
        $this->postPaystack(['event' => 'subscription.not_renew', 'data' => ['subscription_code' => 'SUB_vsyqdmlzble3uii']])->assertOk();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertEquals($subscription->current_period_end, $subscription->access_until);
    }

    public function test_refund_revokes_entitlements(): void
    {
        $subscription = Subscription::factory()->create();
        $payment = Payment::factory()->create([
            'user_id' => $subscription->user_id, 'subscription_id' => $subscription->id, 'status' => PaymentStatus::Success, 'paid_at' => now(),
        ]);

        $this->postPaystack(['event' => 'refund.processed', 'data' => ['id' => 77, 'transaction_reference' => $payment->reference, 'amount' => 250000]])->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
        $this->assertFalse($subscription->fresh()->grantsAccess());
    }

    public function test_monnify_signed_webhook(): void
    {
        $payment = Payment::factory()->create(['provider' => 'monnify', 'plan_id' => 'plan_monthly', 'amount_kobo' => 250000]);
        $body = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => [
            'transactionReference' => 'MNFY|1', 'paymentReference' => $payment->reference, 'paymentStatus' => 'PAID',
            'amountPaid' => 2500, 'currency' => 'NGN', 'paymentMethod' => 'CARD', 'paidOn' => '2026-11-14 10:00:00.0',
            'cardDetails' => ['cardToken' => 'MNFY_TOKEN', 'last4' => '1111', 'cardType' => 'VISA', 'reusable' => true],
            'customer' => ['email' => 'm@example.com', 'name' => 'M'],
        ]]);

        $this->call('POST', '/api/v1/webhooks/monnify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_MONNIFY_SIGNATURE' => 'nope'], $body)
            ->assertUnauthorized();

        $this->call('POST', '/api/v1/webhooks/monnify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MONNIFY_SIGNATURE' => hash_hmac('sha512', $body, 'monnify_secret'),
        ], $body)->assertOk();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame('MNFY_TOKEN', Subscription::query()->sole()->authorization['token']);
    }
}
