<?php

namespace App\Services\Notifications;

use App\Enums\PushCampaignStatus;
use App\Integrations\Firebase\FcmClient;
use App\Jobs\SendPushCampaign;
use App\Models\Device;
use App\Models\PushCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Admin and system push notifications (backend-handoff H.1). Messages fan out
 * per device token through FCM HTTP v1 so we can segment by platform, version
 * or user and record real delivery counts; invalid tokens are pruned.
 */
class PushCampaignService
{
    public function __construct(private readonly FcmClient $fcm) {}

    public static function isAllowedRoute(?string $route): bool
    {
        if ($route === null || $route === '') {
            return true;
        }

        foreach (config('uec.push.allowed_route_prefixes') as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Devices targeted by a campaign's audience and filters. */
    public function audience(PushCampaign $campaign): Builder
    {
        $filters = $campaign->filters ?? [];

        return Device::query()
            ->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at'))
            ->when($campaign->audience === 'platform' && ! empty($filters['platform']), fn ($q) => $q->where('platform', $filters['platform']))
            ->when($campaign->audience === 'version' && ! empty($filters['app_version']), fn ($q) => $q->where('app_version', $filters['app_version']))
            ->when($campaign->audience === 'users', fn ($q) => $q->whereIn('user_id', (array) ($filters['user_ids'] ?? [])))
            ->when(! empty($filters['locale']), fn ($q) => $q->where('locale', $filters['locale']));
    }

    public function estimate(PushCampaign $campaign): int
    {
        return $this->audience($campaign)->count();
    }

    public function dispatch(PushCampaign $campaign): void
    {
        if (! self::isAllowedRoute($campaign->route)) {
            throw new InvalidArgumentException('The route is not an allowed in-app path.');
        }

        $campaign->forceFill(['status' => PushCampaignStatus::Sending, 'started_at' => now()])->save();
        SendPushCampaign::dispatch($campaign->id)->onQueue('notifications');
    }

    /** Executed by the queued job. */
    public function send(PushCampaign $campaign): void
    {
        if (! $this->fcm->isConfigured()) {
            $campaign->forceFill(['status' => PushCampaignStatus::Failed, 'error' => 'Firebase is not configured.', 'completed_at' => now()])->save();

            return;
        }

        $target = $success = $failure = $invalid = 0;
        $data = array_filter(['route' => $campaign->route, 'type' => $campaign->type, 'campaign_id' => $campaign->id]);

        try {
            $this->audience($campaign)->select(['id', 'token'])->chunkById((int) config('uec.push.batch_size', 500), function ($devices) use ($campaign, $data, &$target, &$success, &$failure, &$invalid) {
                $tokens = $devices->pluck('token')->all();
                $results = $this->fcm->sendMany($tokens, $campaign->title, $campaign->body, $data);

                $badTokens = array_keys(array_filter($results, fn ($r) => $r === FcmClient::RESULT_INVALID_TOKEN));
                if ($badTokens) {
                    Device::query()->whereIn('token', $badTokens)->delete();
                }

                $target += count($tokens);
                $success += count(array_filter($results, fn ($r) => $r === FcmClient::RESULT_OK));
                $invalid += count($badTokens);
                $failure += count(array_filter($results, fn ($r) => $r !== FcmClient::RESULT_OK));

                $campaign->forceFill(['target_count' => $target, 'success_count' => $success, 'failure_count' => $failure, 'invalid_token_count' => $invalid])->save();
            });

            $campaign->forceFill(['status' => PushCampaignStatus::Sent, 'completed_at' => now()])->save();
        } catch (Throwable $e) {
            Log::error('push.campaign_failed', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
            $campaign->forceFill(['status' => PushCampaignStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000), 'completed_at' => now()])->save();
        }
    }

    /** Scheduler: send campaigns whose time has come. */
    public function dispatchDue(): int
    {
        $due = PushCampaign::query()
            ->where('status', PushCampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->get();

        $due->each(fn (PushCampaign $c) => $this->dispatch($c));

        return $due->count();
    }

    /** System-generated broadcast (scheduled reminders). */
    public function broadcast(string $title, string $body, ?string $route, string $type): PushCampaign
    {
        $campaign = PushCampaign::query()->create([
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 500),
            'route' => $route,
            'type' => $type,
            'audience' => 'all',
            'source' => 'system',
            'status' => PushCampaignStatus::Draft,
        ]);
        $this->dispatch($campaign);

        return $campaign;
    }
}
