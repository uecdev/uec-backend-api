<?php

namespace App\Services\Account;

use App\Enums\SubscriptionStatus;
use App\Enums\VerificationPurpose;
use App\Events\AccountEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TokenService;
use App\Services\Auth\VerificationService;
use App\Services\Media\MediaService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Profile contact changes and account deletion (backend-handoff B.2, D.7, I.4).
 */
class AccountService
{
    public function __construct(
        private readonly VerificationService $verifications,
        private readonly TokenService $tokens,
        private readonly SubscriptionService $subscriptions,
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
    ) {}

    public function startContactChange(User $user, string $channel, string $value, Request $request): array
    {
        $column = $channel === 'phone' ? 'phone' : 'email';
        if (User::withTrashed()->where($column, $value)->where('id', '!=', $user->id)->exists()) {
            throw ApiException::validation('value', __('api.contact_in_use', ['channel' => $channel === 'phone' ? 'phone number' : 'email address']));
        }

        return $this->verifications->start(VerificationPurpose::Contact, $channel, $value, $user->id,
            draft: ['channel' => $channel, 'value' => $value], ip: $request->ip(), locale: $user->preferredLocale());
    }

    public function completeContactChange(User $user, string $verificationId, string $code): User
    {
        $result = $this->verifications->verify($verificationId, $code, [VerificationPurpose::Contact]);
        if ($result['user_id'] !== $user->id) {
            throw new ApiException(410, __('api.otp_expired'), 'OTP_EXPIRED');
        }

        $channel = $result['draft']['channel'];
        $value = $result['draft']['value'];
        $column = $channel === 'phone' ? 'phone' : 'email';
        $previous = $user->{$column};

        DB::transaction(function () use ($user, $column, $value) {
            if (User::withTrashed()->where($column, $value)->where('id', '!=', $user->id)->lockForUpdate()->exists()) {
                throw ApiException::validation('value', __('api.contact_in_use', ['channel' => $column]));
            }
            $user->forceFill([$column => $value, "{$column}_verified_at" => now()])->save();
        });

        // Tell the previous address (if any) that it was replaced.
        AccountEvent::dispatch("{$column}_changed", $user,
            email: $column === 'email' ? $previous : null,
            phone: $column === 'phone' ? $previous : null,
            name: $user->name, locale: $user->preferredLocale());
        if (! $previous) {
            AccountEvent::dispatch("{$column}_changed", $user, locale: $user->preferredLocale());
        }

        $this->audit->log("account.{$column}_changed", $user, null, [], $user);

        return $user->refresh();
    }

    /**
     * In-app deletion (Apple/Google requirement): cancel the subscription at the gateway,
     * revoke all sessions, delete personal data and anonymise the account row.
     * Payment records are retained (anonymised user) for financial record-keeping.
     */
    public function delete(User $user): void
    {
        $contact = ['email' => $user->email, 'phone' => $user->phone, 'name' => $user->name, 'locale' => $user->preferredLocale()];

        $subscription = $this->subscriptions->current($user);
        if ($subscription && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            try {
                $this->subscriptions->cancel($subscription, 'Account deleted', 'account_deletion');
            } catch (Throwable $e) {
                Log::error('account.delete_cancel_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            $this->media->deleteAvatar($user);
        } catch (Throwable $e) {
            Log::warning('account.delete_avatar_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($user) {
            $this->tokens->revokeAll($user, 'account_deleted');
            $user->devices()->delete();
            $user->favourites()->delete();
            $user->programs()->delete();
            $user->devotionalReads()->delete();
            $user->activityDays()->delete();
            $user->syncRoles([]);

            $user->forceFill([
                'name' => null,
                'phone' => null,
                'email' => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,
                'google_sub' => null,
                'avatar_url' => null,
                'avatar_path' => null,
                'password' => Str::random(64),
                'remember_token' => null,
            ])->save();
            $user->delete(); // soft delete keeps FK integrity for retained payment records
        });

        $this->audit->log('account.deleted', $user, 'Account deleted by the user', [], $user);

        AccountEvent::dispatch('deleted', null, $contact['email'], $contact['phone'], $contact['name'], $contact['locale']);
    }
}
