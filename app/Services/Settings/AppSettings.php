<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Non-secret runtime configuration editable from the admin dashboard
 * (remote app config, provider ordering, scheduled-push toggles).
 */
class AppSettings
{
    private const CACHE_KEY = 'app_settings:all';

    /** Defaults applied when a key has never been saved. */
    public const DEFAULTS = [
        'donation_url' => null,
        'support_email' => null,
        'min_supported_version' => '1.0.0',
        'latest_version' => '1.0.0',
        'store_url_android' => null,
        'store_url_ios' => null,
        // Order matters: the first entry is the provider the app uses for checkout (§C.4 #19).
        'payment_providers' => ['paystack', 'monnify'],
        'maintenance_message' => null,
        'update_message' => null,
        // [CONFIRM] J.1 #8 — scheduled pushes are implemented but disabled until UEC confirms.
        'push_dde_daily_enabled' => false,
        'push_dde_daily_time' => '06:00',
        'push_ssm_reminder_enabled' => false,
        'push_ssm_reminder_time' => '18:00',
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public function put(array $values, ?string $userId = null): void
    {
        foreach ($values as $key => $value) {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<string> enabled providers, in checkout preference order */
    public function paymentProviders(): array
    {
        return array_values(array_intersect((array) $this->get('payment_providers', []), ['paystack', 'monnify']));
    }
}
