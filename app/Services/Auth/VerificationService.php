<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Jobs\DeliverOtp;
use App\Support\Api\ApiException;
use App\Support\Email;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * OTP challenges (backend-handoff B.1, D.1, I.1). Stored in the cache store
 * (Redis in production), never in the users table:
 *  - 6 digits, valid 10 minutes, resend after 45 s, max 5 attempts
 *  - codes are HMAC-hashed and compared in constant time; single use
 *  - draft payloads (pending registration) are encrypted at rest
 */
class VerificationService
{
    private const PREFIX = 'verification:';

    /** Extra cache lifetime beyond expiry so an expired id yields 410 rather than "unknown". */
    private const RETENTION_SECONDS = 3600;

    /**
     * @param  array<string, mixed>|null  $draft
     * @param  bool  $deliver  false for enumeration-safe challenges on unknown accounts
     * @return array{verification_id: string, channel: string, destination_masked: string, expires_in: int, resend_in: int}
     */
    public function start(VerificationPurpose $purpose, string $channel, string $destination, ?string $userId = null, ?array $draft = null, bool $deliver = true, ?string $ip = null, ?string $locale = null): array
    {
        if ($deliver) {
            $this->throttleSending($destination, $ip);
        }

        $id = 'ver_'.strtolower((string) Str::ulid());
        $code = $this->generateCode();
        $now = now()->getTimestamp();

        $record = [
            'purpose' => $purpose->value,
            'channel' => $channel,
            'destination' => $destination,
            'user_id' => $userId,
            'draft' => $draft !== null ? Crypt::encryptString(json_encode($draft)) : null,
            'code_hash' => $this->hashCode($id, $code),
            'attempts' => 0,
            'resends' => 0,
            'expires_at' => $now + (int) config('uec.otp.ttl_seconds'),
            'resend_at' => $now + (int) config('uec.otp.resend_cooldown_seconds'),
            'deliver' => $deliver,
            'locale' => $locale,
        ];
        $this->store($id, $record);

        if ($deliver) {
            DeliverOtp::dispatch($channel, $destination, $code, $purpose->value, $locale ?? app()->getLocale())->onQueue('otp');
        }

        return $this->challenge($id, $record);
    }

    /** @return array{resend_in: int, expires_in: int} */
    public function resend(string $id, ?string $ip = null): array
    {
        $record = $this->load($id);
        $now = now()->getTimestamp();

        if ($record['attempts'] >= (int) config('uec.otp.max_attempts')) {
            throw new ApiException(429, __('api.otp_locked'), 'OTP_LOCKED');
        }
        if ($now < $record['resend_at']) {
            $wait = $record['resend_at'] - $now;
            throw new ApiException(429, __('api.otp_resend_wait', ['seconds' => $wait]), 'OTP_RESEND_TOO_SOON', [], ['Retry-After' => (string) $wait]);
        }
        if ($record['resends'] >= (int) config('uec.otp.max_resends')) {
            throw new ApiException(429, __('api.otp_resend_limit'), 'OTP_LOCKED');
        }

        $code = $this->generateCode();
        $record['code_hash'] = $this->hashCode($id, $code); // invalidates the previous code
        $record['resends']++;
        $record['expires_at'] = $now + (int) config('uec.otp.ttl_seconds');
        $record['resend_at'] = $now + (int) config('uec.otp.resend_cooldown_seconds');
        $this->store($id, $record);

        if ($record['deliver']) {
            $this->throttleSending($record['destination'], $ip);
            DeliverOtp::dispatch($record['channel'], $record['destination'], $code, $record['purpose'], $record['locale'] ?? app()->getLocale())->onQueue('otp');
        }

        return [
            'resend_in' => (int) config('uec.otp.resend_cooldown_seconds'),
            'expires_in' => (int) config('uec.otp.ttl_seconds'),
        ];
    }

    /**
     * Check a code. On success the challenge is consumed and its record returned
     * (with the decrypted draft). Errors: 410 OTP_EXPIRED, 429 OTP_LOCKED, 422 errors.code.
     *
     * @param  list<VerificationPurpose>  $purposes
     * @return array{purpose: string, channel: string, destination: string, user_id: ?string, draft: ?array}
     */
    public function verify(string $id, string $code, array $purposes): array
    {
        $record = $this->load($id);

        if (! in_array($record['purpose'], array_map(fn ($p) => $p->value, $purposes), true)) {
            throw new ApiException(410, __('api.otp_expired'), 'OTP_EXPIRED');
        }
        if (now()->getTimestamp() > $record['expires_at']) {
            throw new ApiException(410, __('api.otp_expired'), 'OTP_EXPIRED');
        }
        if ($record['attempts'] >= (int) config('uec.otp.max_attempts')) {
            throw new ApiException(429, __('api.otp_locked'), 'OTP_LOCKED');
        }

        // Unknown-account challenges (forgot password) can never succeed but behave identically.
        $valid = $record['deliver'] && hash_equals($record['code_hash'], $this->hashCode($id, $code));

        if (! $valid) {
            $record['attempts']++;
            $this->store($id, $record);
            if ($record['attempts'] >= (int) config('uec.otp.max_attempts')) {
                throw new ApiException(429, __('api.otp_locked'), 'OTP_LOCKED');
            }
            throw ApiException::validation('code', __('api.otp_invalid'));
        }

        Cache::forget(self::PREFIX.$id); // single use

        return [
            'purpose' => $record['purpose'],
            'channel' => $record['channel'],
            'destination' => $record['destination'],
            'user_id' => $record['user_id'],
            'draft' => $record['draft'] ? json_decode(Crypt::decryptString($record['draft']), true) : null,
        ];
    }

    private function challenge(string $id, array $record): array
    {
        $now = now()->getTimestamp();

        return [
            'verification_id' => $id,
            'channel' => $record['channel'],
            'destination_masked' => Email::maskDestination($record['channel'], $record['destination']),
            'expires_in' => max(0, $record['expires_at'] - $now),
            'resend_in' => max(0, $record['resend_at'] - $now),
        ];
    }

    private function load(string $id): array
    {
        $record = str_starts_with($id, 'ver_') ? Cache::get(self::PREFIX.$id) : null;
        if (! is_array($record)) {
            throw new ApiException(410, __('api.otp_expired'), 'OTP_EXPIRED');
        }

        return $record;
    }

    private function store(string $id, array $record): void
    {
        Cache::put(self::PREFIX.$id, $record, (int) config('uec.otp.ttl_seconds') + self::RETENTION_SECONDS);
    }

    private function generateCode(): string
    {
        $length = (int) config('uec.otp.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }

    /** OTP pumping protection: cap sends per destination and per IP per hour. */
    private function throttleSending(string $destination, ?string $ip): void
    {
        $limits = [
            'otp:dest:'.sha1($destination) => (int) config('uec.otp.max_per_destination_per_hour'),
        ];
        if ($ip) {
            $limits['otp:ip:'.$ip] = (int) config('uec.otp.max_per_ip_per_hour');
        }

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = RateLimiter::availableIn($key);
                throw new ApiException(429, __('api.otp_too_many'), 'OTP_LOCKED', [], ['Retry-After' => (string) $wait]);
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }
    }
}
