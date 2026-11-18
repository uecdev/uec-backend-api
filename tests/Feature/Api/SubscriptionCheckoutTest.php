<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->configurePaystack();
        $this->configureMonnify();
    }

    private function paystackVerifyResponse(string $reference, int $amount = 1990000, string $status = 'success'): array
    {
        return ['status' => true, 'message' => 'Verification successful', 'data' => [
            'id' => 4099260516, 'status' => $status, 'reference' => $reference, 'amount' => $amount, 'currency' => 'NGN',
            'channel' => 'card', 'gateway_response' => 'Approved', 'paid_at' => '2026-11-14T10:00:00.000Z',
            'authorization' => ['authorization_code' => 'AUTH_8dfhjjdt', 'card_type' => 'visa', 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030', 'reusable' => true],
            'customer' => ['customer_code' => 'CUS_xnxdt6s1zg1f4nx', 'email' => 'member@example.com'],
            'plan' => null,
        ]];
    }

    public function test_paystack_checkout_uses_database_amount_and_https_callback(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'ignored',
        ]])]);
        $user = $this->member(['email' => null]);

        $res = $this->postJson('/api/v1/me/subscription/checkout', [
            'plan_id' => 'plan_yearly', 'provider' => 'paystack', 'callback_url' => 'uecapp://payment/callback', 'amount' => 1,
        ], $this->tokenFor($user))->assertOk()
            ->assertJsonPath('data.provider', 'paystack')
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/abc123');

        $reference = $res->json('data.reference');
        $this->assertStringStartsWith('uec_pay_', $reference);

        Http::assertSent(function ($request) use ($reference, $user) {
            return $request['amount'] === 1990000
                && $request['reference'] === $reference
                && $request['callback_url'] === 'https://api.uec.test/api/v1/payments/callback'
                && $request['email'] === strtolower($user->id).'@users.uec' // phone-only user placeholder
                && $request->hasHeader('Authorization', 'Bearer sk_test_secret');
        });

        $this->assertDatabaseHas('payments', ['reference' => $reference, 'amount_kobo' => 1990000, 'status' => 'pending', 'plan_id' => 'plan_yearly']);
    }

    public function test_checkout_rejects_unknown_provider_open_redirects_and_existing_subscribers(): void
    {
        $user = $this->member();
        $auth = $this->tokenFor($user);

        $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_yearly', 'provider' => 'stripe'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['provider']);
        $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_nope', 'provider' => 'paystack'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);
        $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_yearly', 'provider' => 'paystack', 'callback_url' => 'https://evil.example/steal'], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['callback_url']);

        Subscription::factory()->create(['user_id' => $user->id]);
        $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_yearly', 'provider' => 'paystack'], $auth)
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_SUBSCRIBED');
    }

    public function test_gateway_outage_returns_502_and_marks_payment_failed(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Internal error'], 500)]);
        $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_monthly', 'provider' => 'paystack'], $this->tokenFor($this->member()))
            ->assertStatus(502)->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');
        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
        $this->assertDatabaseHas('integration_logs', ['integration' => 'paystack', 'success' => false]);
    }

    public function test_verify_activates_subscription_when_webhook_is_delayed(): void
    {
        $user = $this->member();
        $payment = Payment::factory()->create(['user_id' => $user->id, 'plan_id' => 'plan_yearly', 'amount_kobo' => 1990000]);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->paystackVerifyResponse($payment->reference))]);

        $res = $this->postJson('/api/v1/me/subscription/verify', ['reference' => $payment->reference], $this->tokenFor($user))->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan.id', 'plan_yearly')
            ->assertJsonPath('data.subscription.payment_method.label', 'Visa •••• 4081');

        $this->assertNotNull($res->json('data.subscription.current_period_end'));

        $this->getJson('/api/v1/me/subscription', $this->tokenFor($user))->assertOk()
            ->assertJsonPath('data.entitlements', ['hymn.audio', 'hymn.solfa', 'offline.downloads', 'archive.editions']);
        $this->getJson('/api/v1/me', $this->tokenFor($user))->assertJsonPath('data.is_premium', true);

        // Idempotent: polling again does not create another subscription.
        $this->postJson('/api/v1/me/subscription/verify', ['reference' => $payment->reference], $this->tokenFor($user))->assertJsonPath('data.status', 'success');
        $this->assertSame(1, Subscription::query()->count());
        Notification::assertSentTo($user, PaymentReceiptNotification::class);
        Notification::assertSentTo($user, SubscriptionNotification::class, fn ($n) => $n->event === 'started');
    }

    public function test_verify_rejects_amount_tampering_and_keeps_payment_pending(): void
    {
        $user = $this->member();
        $payment = Payment::factory()->create(['user_id' => $user->id, 'plan_id' => 'plan_yearly', 'amount_kobo' => 1990000]);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->paystackVerifyResponse($payment->reference, 100))]);

        $this->postJson('/api/v1/me/subscription/verify', ['reference' => $payment->reference], $this->tokenFor($user))
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.subscription', null);

        $this->assertSame(0, Subscription::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.payment_mismatch']);
    }

    public function test_verify_reports_abandoned_and_hides_other_users_references(): void
    {
        $user = $this->member();
        $payment = Payment::factory()->create(['user_id' => $user->id]);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response($this->paystackVerifyResponse($payment->reference, 250000, 'abandoned'))]);

        $this->postJson('/api/v1/me/subscription/verify', ['reference' => $payment->reference], $this->tokenFor($user))
            ->assertOk()->assertJsonPath('data.status', 'abandoned');

        $this->postJson('/api/v1/me/subscription/verify', ['reference' => $payment->reference], $this->tokenFor($this->member()))
            ->assertNotFound();
    }

    public function test_monnify_checkout_and_verify(): void
    {
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response(['requestSuccessful' => true, 'responseBody' => ['accessToken' => 'mn_token', 'expiresIn' => 3600]]),
            'sandbox.monnify.com/api/v1/merchant/transactions/init-transaction' => Http::response(['requestSuccessful' => true, 'responseBody' => [
                'transactionReference' => 'MNFY|20261114|000001', 'checkoutUrl' => 'https://sandbox.sdk.monnify.com/checkout/MNFY|20261114|000001',
            ]]),
            'sandbox.monnify.com/api/v2/merchant/transactions/query*' => fn ($request) => Http::response(['requestSuccessful' => true, 'responseBody' => [
                'paymentReference' => $request['paymentReference'], 'transactionReference' => 'MNFY|20261114|000001',
                'paymentStatus' => 'PAID', 'amountPaid' => '2500.00', 'currencyCode' => 'NGN', 'paymentMethod' => 'CARD',
                'paidOn' => '2026-11-14 11:00:00', 'cardDetails' => ['cardToken' => 'MNFY_CARD_TOKEN', 'cardType' => 'MASTERCARD', 'last4' => '1234', 'reusable' => true],
                'customer' => ['email' => 'member@example.com'],
            ]]),
        ]);
        $user = $this->member();
        $auth = $this->tokenFor($user);

        $reference = $this->postJson('/api/v1/me/subscription/checkout', ['plan_id' => 'plan_monthly', 'provider' => 'monnify'], $auth)
            ->assertOk()->assertJsonPath('data.provider', 'monnify')->json('data.reference');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'init-transaction') && $r['amount'] == 2500 && $r['paymentReference'] === $reference && $r['paymentMethods'] === ['CARD']);

        $this->postJson('/api/v1/me/subscription/verify', ['reference' => $reference], $auth)
            ->assertOk()->assertJsonPath('data.status', 'success')->assertJsonPath('data.subscription.plan.id', 'plan_monthly');

        $subscription = Subscription::query()->sole();
        $this->assertSame('monnify', $subscription->provider);
        $this->assertSame('MNFY_CARD_TOKEN', $subscription->authorization['token']);
    }

    public function test_payment_callback_redirects_to_app_scheme(): void
    {
        $payment = Payment::factory()->create(['app_callback_url' => 'uecapp://payment/callback']);

        $this->get('/api/v1/payments/callback?reference='.$payment->reference)
            ->assertStatus(302)
            ->assertHeader('Location', 'uecapp://payment/callback?reference='.$payment->reference)
            ->assertSee('Return to the app');

        // Untrusted input never alters the redirect target.
        $this->get('/api/v1/payments/callback?reference=%3Cscript%3E&redirect=https://evil.example')
            ->assertStatus(302)->assertHeader('Location', 'uecapp://payment/callback?reference=');
    }

    public function test_subscription_state_for_free_user(): void
    {
        $this->getJson('/api/v1/me/subscription', $this->tokenFor($this->member()))
            ->assertOk()->assertExactJson(['data' => ['subscription' => null, 'entitlements' => []]]);
    }

    public function test_subscription_status_values(): void
    {
        $user = $this->member();
        Subscription::factory()->create(['user_id' => $user->id, 'status' => SubscriptionStatus::PastDue, 'past_due_at' => now()->subDay()]);

        $this->getJson('/api/v1/me/subscription', $this->tokenFor($user))
            ->assertJsonPath('data.subscription.status', 'past_due')
            ->assertJsonCount(4, 'data.entitlements'); // within grace
    }
}
