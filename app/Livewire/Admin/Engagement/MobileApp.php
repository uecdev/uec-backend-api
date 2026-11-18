<?php

namespace App\Livewire\Admin\Engagement;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\Device;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Mobile app')]
class MobileApp extends Component
{
    use AdminComponent;

    public string $donation_url = '';

    public string $support_email = '';

    public string $min_supported_version = '';

    public string $latest_version = '';

    public string $store_url_android = '';

    public string $store_url_ios = '';

    /** @var list<string> */
    public array $providers = [];

    public string $maintenance_message = '';

    public string $update_message = '';

    public bool $push_dde_daily_enabled = false;

    public string $push_dde_daily_time = '06:00';

    public bool $push_ssm_reminder_enabled = false;

    public string $push_ssm_reminder_time = '18:00';

    public function mount(AppSettings $settings): void
    {
        $this->authorizeAbility('app.manage');
        $s = $settings->all();
        foreach (['donation_url', 'support_email', 'min_supported_version', 'latest_version', 'store_url_android', 'store_url_ios', 'maintenance_message', 'update_message', 'push_dde_daily_time', 'push_ssm_reminder_time'] as $k) {
            $this->{$k} = (string) ($s[$k] ?? '');
        }
        $this->push_dde_daily_enabled = (bool) $s['push_dde_daily_enabled'];
        $this->push_ssm_reminder_enabled = (bool) $s['push_ssm_reminder_enabled'];
        $enabled = (array) $s['payment_providers'];
        $this->providers = array_values(array_unique(array_merge($enabled, PaymentGatewayManager::PROVIDERS)));
        $this->enabledProviders = $enabled;
    }

    /** @var list<string> */
    public array $enabledProviders = [];

    public function moveProvider(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (isset($this->providers[$index], $this->providers[$target])) {
            [$this->providers[$index], $this->providers[$target]] = [$this->providers[$target], $this->providers[$index]];
        }
    }

    public function save(AppSettings $settings): void
    {
        $this->authorizeAbility('app.manage');
        $semver = 'regex:/^\d+\.\d+\.\d+$/';
        $data = $this->validate([
            'donation_url' => ['nullable', 'url:https', 'max:500'],
            'support_email' => ['nullable', 'email', 'max:191'],
            'min_supported_version' => ['required', $semver],
            'latest_version' => ['required', $semver],
            'store_url_android' => ['nullable', 'url:https', 'max:500'],
            'store_url_ios' => ['nullable', 'url:https', 'max:500'],
            'enabledProviders' => ['array'],
            'enabledProviders.*' => ['in:paystack,monnify'],
            'maintenance_message' => ['nullable', 'string', 'max:300'],
            'update_message' => ['nullable', 'string', 'max:300'],
            'push_dde_daily_enabled' => ['boolean'],
            'push_dde_daily_time' => ['required', 'date_format:H:i'],
            'push_ssm_reminder_enabled' => ['boolean'],
            'push_ssm_reminder_time' => ['required', 'date_format:H:i'],
        ], ['min_supported_version.regex' => 'Use semantic versioning, e.g. 1.0.0', 'latest_version.regex' => 'Use semantic versioning, e.g. 1.2.0']);

        if (version_compare($data['min_supported_version'], $data['latest_version'], '>')) {
            $this->addError('min_supported_version', 'The minimum supported version cannot be newer than the latest version.');

            return;
        }

        $before = $settings->all();
        $values = collect($data)->except(['enabledProviders'])->map(fn ($v) => $v === '' ? null : $v)->all();
        $values['payment_providers'] = array_values(array_filter($this->providers, fn ($p) => in_array($p, $this->enabledProviders, true)));
        $settings->put($values, auth()->id());

        $changed = collect($values)->filter(fn ($v, $k) => ($before[$k] ?? null) !== $v)->keys()->all();
        $this->audit('app_settings.updated', null, 'Mobile app configuration', ['changed' => $changed]);
        $this->toast('Configuration saved. Apps pick it up on their next launch.');
    }

    public function render(PaymentGatewayManager $gateways)
    {
        $versions = Device::query()->select('platform', 'app_version', DB::raw('count(*) as total'))
            ->groupBy('platform', 'app_version')->orderByDesc('total')->get();
        $total = (int) $versions->sum('total');
        $supported = $versions->filter(fn ($v) => $v->app_version && version_compare($v->app_version, $this->min_supported_version ?: '0.0.0', '>='))->sum('total');
        $latest = $versions->filter(fn ($v) => $v->app_version && version_compare($v->app_version, $this->latest_version ?: '0.0.0', '>='))->sum('total');

        return view('livewire.admin.engagement.mobile-app', [
            'versions' => $versions,
            'deviceTotal' => $total,
            'supportedPct' => $total ? round($supported / $total * 100, 1) : null,
            'latestPct' => $total ? round($latest / $total * 100, 1) : null,
            'configured' => collect(PaymentGatewayManager::PROVIDERS)->mapWithKeys(fn ($p) => [$p => $gateways->gateway($p)->isConfigured()])->all(),
        ]);
    }
}
