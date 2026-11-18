<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Events\AccountEvent;
use App\Integrations\Google\GoogleIdTokenVerifier;
use App\Integrations\IntegrationException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Registration, login, Google sign-in and password reset (backend-handoff D.2–D.6).
 */
class AuthService
{
    public function __construct(
        private readonly VerificationService $verifications,
        private readonly TokenService $tokens,
        private readonly GoogleIdTokenVerifier $google,
        private readonly AuditLogger $audit,
    ) {}

    /** Step 1: the pending registration lives in the cache (encrypted draft), not in users. */
    public function startRegistration(string $channel, string $destination, string $password, Request $request): array
    {
        return $this->verifications->start(
            VerificationPurpose::Register,
            $channel,
            $destination,
            draft: ['channel' => $channel, 'destination' => $destination, 'password_hash' => Hash::make($password)],
            ip: $request->ip(),
            locale: app()->getLocale(),
        );
    }

    /**
     * Step 2 (also used for "account" challenges issued at login for unverified accounts).
     *
     * @return array{user: User, tokens: array}
     */
    public function completeVerification(string $verificationId, string $code, ?string $deviceName, Request $request): array
    {
        $result = $this->verifications->verify($verificationId, $code, [VerificationPurpose::Register, VerificationPurpose::Account]);
        $column = $result['channel'] === 'phone' ? 'phone' : 'email';

        if ($result['purpose'] === VerificationPurpose::Account->value) {
            $user = User::query()->findOrFail($result['user_id']);
            $user->forceFill(["{$column}_verified_at" => now(), 'last_login_at' => now()])->save();

            return ['user' => $user, 'tokens' => $this->tokens->issue($user, $deviceName, $request)];
        }

        $draft = (array) $result['draft'];

        $user = DB::transaction(function () use ($draft, $column) {
            // Re-check uniqueness: the identifier may have been taken while the OTP was pending.
            if (User::withTrashed()->where($column, $draft['destination'])->exists()) {
                throw ApiException::validation($column, __('api.already_registered'));
            }

            $user = new User;
            $user->forceFill([
                $column => $draft['destination'],
                "{$column}_verified_at" => now(),
                'password' => $draft['password_hash'], // already hashed; the "hashed" cast stores it as-is
                'locale' => in_array(app()->getLocale(), config('uec.locales'), true) ? app()->getLocale() : 'en',
                'last_login_at' => now(),
            ]);
            $user->save();
            $user->assignRole('member');

            return $user;
        });

        AccountEvent::dispatch('registered', $user, locale: $user->preferredLocale());
        $this->audit->log('auth.registered', $user, "Registered via {$column}", actor: $user);

        return ['user' => $user, 'tokens' => $this->tokens->issue($user, $deviceName, $request)];
    }

    /** @return array{user: User, tokens: array} */
    public function login(string $channel, string $identifier, string $password, ?string $deviceName, Request $request): array
    {
        $throttleKey = 'login:'.sha1(mb_strtolower($identifier)).'|'.$request->ip();
        $max = (int) config('uec.auth.login_max_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $max)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw new ApiException(429, __('api.login_throttled', ['seconds' => $seconds]), 'TOO_MANY_ATTEMPTS', [], ['Retry-After' => (string) $seconds]);
        }

        $column = $channel === 'phone' ? 'phone' : 'email';
        $user = User::query()->where($column, $identifier)->first();

        // Always run a hash check so response timing does not reveal whether the account exists.
        if ($user && $user->password) {
            $valid = Hash::check($password, $user->password);
        } else {
            Hash::check($password, $this->dummyHash());
            $valid = false;
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey, (int) config('uec.auth.login_decay_seconds'));
            throw ApiException::validation('identifier', __('api.invalid_credentials'));
        }

        RateLimiter::clear($throttleKey);
        $this->assertNotSuspended($user);

        if ($user->{"{$column}_verified_at"} === null) {
            $challenge = $this->verifications->start(VerificationPurpose::Account, $channel, $identifier, $user->id, ip: $request->ip(), locale: $user->preferredLocale());
            throw new ApiException(403, __('api.account_unverified'), 'ACCOUNT_UNVERIFIED', ['verification' => $challenge]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return ['user' => $user, 'tokens' => $this->tokens->issue($user, $deviceName, $request)];
    }

    /** Link by Google sub, then by verified email; otherwise create (D.4). */
    public function loginWithGoogle(string $idToken, ?string $deviceName, Request $request): array
    {
        try {
            $claims = $this->google->verify($idToken);
        } catch (IntegrationException $e) {
            Log::error('auth.google_unavailable', ['error' => $e->getMessage()]);
            throw new ApiException(503, __('api.google_unavailable'), 'GOOGLE_UNAVAILABLE');
        }

        if (! $claims) {
            throw ApiException::validation('id_token', __('api.google_invalid'), 401);
        }

        $created = false;
        $user = DB::transaction(function () use ($claims, &$created) {
            $user = User::query()->where('google_sub', $claims['sub'])->lockForUpdate()->first();

            if (! $user && $claims['email']) {
                $user = User::query()->where('email', $claims['email'])->lockForUpdate()->first();
                $user?->forceFill([
                    'google_sub' => $claims['sub'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }

            if (! $user) {
                $user = new User;
                $user->forceFill([
                    'google_sub' => $claims['sub'],
                    'email' => $claims['email'],
                    'email_verified_at' => now(),
                    'name' => $claims['name'],
                    'avatar_url' => $claims['picture'],
                    'locale' => in_array(app()->getLocale(), config('uec.locales'), true) ? app()->getLocale() : 'en',
                ])->save();
                $user->assignRole('member');
                $created = true;
            }

            return $user;
        });

        $this->assertNotSuspended($user);
        $user->forceFill(['last_login_at' => now()])->save();

        if ($created) {
            AccountEvent::dispatch('registered', $user, locale: $user->preferredLocale());
            $this->audit->log('auth.registered', $user, 'Registered via Google', actor: $user);
        }

        return ['user' => $user, 'tokens' => $this->tokens->issue($user, $deviceName, $request)];
    }

    /** Always returns a challenge, even for unknown accounts (no enumeration, D.5). */
    public function forgotPassword(string $channel, string $identifier, Request $request): array
    {
        $column = $channel === 'phone' ? 'phone' : 'email';
        $user = User::query()->where($column, $identifier)->first();
        $deliver = $user !== null && ! $user->isSuspended();

        return $this->verifications->start(VerificationPurpose::Reset, $channel, $identifier, $user?->id, deliver: $deliver, ip: $request->ip(), locale: $user?->preferredLocale());
    }

    /** @return string single-use reset token valid for 15 minutes */
    public function verifyPasswordReset(string $verificationId, string $code): string
    {
        $result = $this->verifications->verify($verificationId, $code, [VerificationPurpose::Reset]);

        $token = 'rst_'.Str::random(64);
        Cache::put('password_reset:'.hash('sha256', $token), $result['user_id'], now()->addMinutes((int) config('uec.auth.reset_token_ttl_minutes')));

        return $token;
    }

    public function resetPassword(string $resetToken, string $password): void
    {
        $key = 'password_reset:'.hash('sha256', $resetToken);
        $userId = Cache::pull($key); // single use
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user) {
            throw new ApiException(410, __('api.reset_expired'), 'RESET_EXPIRED');
        }

        $user->forceFill(['password' => $password])->save();
        $this->tokens->revokeAll($user, 'password_reset');

        AccountEvent::dispatch('password_changed', $user, locale: $user->preferredLocale());
        $this->audit->log('auth.password_reset', $user, 'Password reset; all sessions revoked', actor: $user);
    }

    private function dummyHash(): string
    {
        return Cache::rememberForever('auth:dummy_hash', fn () => Hash::make(Str::random(32)));
    }

    private function assertNotSuspended(User $user): void
    {
        if ($user->isSuspended()) {
            throw new ApiException(403, __('api.account_suspended'), 'ACCOUNT_SUSPENDED');
        }
    }
}
