<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillingResources;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** #45–#51 Plans, subscription state, checkout, verify, change, cancel (backend-handoff E). */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentService $payments,
    ) {}

    public function plans(): JsonResponse
    {
        $plans = Plan::query()->active()->orderBy('sort')->orderBy('amount_kobo')->get();

        return $this->data($plans->map(fn ($p) => BillingResources::plan($p))->all(), 200, ['Cache-Control' => 'public, max-age=600']);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptions->current($user);

        return $this->data([
            'subscription' => $subscription ? BillingResources::subscription($subscription) : null,
            'entitlements' => $this->subscriptions->entitlements($user),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('is_active', true)],
            'provider' => ['required', Rule::in(['paystack', 'monnify'])],
            'callback_url' => ['nullable', 'string', 'max:255'],
        ]);

        $callback = $this->validatedCallback($data['callback_url'] ?? null);
        $payment = $this->payments->checkout($request->user(), Plan::query()->findOrFail($data['plan_id']), $data['provider'], $callback);

        return $this->data([
            'reference' => $payment->reference,
            'provider' => $payment->provider,
            'authorization_url' => $payment->authorization_url,
            'expires_at' => Iso::ts($payment->expires_at),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);
        $payment = $this->payments->verifyForUser($request->user(), $data['reference']);
        $subscription = $payment->status === PaymentStatus::Success ? $this->subscriptions->current($request->user()) : null;

        return $this->data([
            'status' => $payment->status === PaymentStatus::Refunded ? 'failed' : $payment->status->value,
            'subscription' => $subscription ? BillingResources::subscription($subscription) : null,
        ]);
    }

    public function changePreview(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', Rule::exists('plans', 'id')]]);
        $preview = $this->subscriptions->previewChange($this->requireSubscription($request), Plan::query()->findOrFail($data['plan_id']));

        return $this->data([
            'plan' => BillingResources::plan($preview['plan']),
            'current_plan' => BillingResources::plan($preview['current_plan']),
            'effective_date' => $preview['effective_date'],
            'amount' => $preview['amount'],
        ]);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', Rule::exists('plans', 'id')]]);
        $subscription = $this->subscriptions->changePlan($this->requireSubscription($request), Plan::query()->findOrFail($data['plan_id']));

        return $this->data(BillingResources::subscription($subscription));
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $subscription = $this->subscriptions->cancel($this->requireSubscription($request), $data['reason'] ?? null);

        return $this->data(BillingResources::subscription($subscription));
    }

    private function requireSubscription(Request $request): Subscription
    {
        $subscription = $this->subscriptions->current($request->user());
        if (! $subscription) {
            throw new ApiException(404, __('api.subscription_not_found'), 'SUBSCRIPTION_NOT_FOUND');
        }

        return $subscription;
    }

    /** Accept only the app's scheme URL or an approved https host — no open redirects (I.3). */
    private function validatedCallback(?string $url): string
    {
        $default = (string) config('uec.payments.app_callback_url');
        if ($url === null || $url === '' || $url === $default) {
            return $default;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (($parts['scheme'] ?? null) === 'https' && $host && in_array($host, config('uec.payments.allowed_callback_hosts'), true)) {
            return $url;
        }

        throw ApiException::validation('callback_url', __('api.invalid_callback_url'));
    }
}
