<?php

namespace App\Services\Notifications;

use App\Models\DevotionalEntry;
use App\Models\PushCampaign;
use App\Models\SsPlanWeek;
use App\Services\Settings\AppSettings;

/**
 * Suggested recurring pushes (backend-handoff H.1 [CONFIRM]): the daily devotional
 * reminder and the Saturday-evening Sunday School reminder. Both are toggled and
 * timed from the admin dashboard and are off until UEC confirms the schedule.
 */
class ScheduledReminders
{
    public function __construct(private readonly AppSettings $settings, private readonly PushCampaignService $push) {}

    /** Called every minute by the scheduler; fires each reminder once at its configured time. */
    public function run(): array
    {
        $now = now(config('uec.timezone'));
        $sent = [];

        if ($this->settings->get('push_dde_daily_enabled') && $now->format('H:i') === $this->settings->get('push_dde_daily_time')) {
            if ($campaign = $this->dailyDevotional()) {
                $sent[] = $campaign->id;
            }
        }

        if ($this->settings->get('push_ssm_reminder_enabled') && $now->isSaturday() && $now->format('H:i') === $this->settings->get('push_ssm_reminder_time')) {
            if ($campaign = $this->sundaySchool()) {
                $sent[] = $campaign->id;
            }
        }

        return $sent;
    }

    public function dailyDevotional(): ?PushCampaign
    {
        $today = now(config('uec.timezone'))->toDateString();
        $entry = DevotionalEntry::query()->visible()->whereDate('date', $today)->first();
        if (! $entry || $this->alreadySent('dde_daily')) {
            return null;
        }

        return $this->push->broadcast("Today's Daily Delight", $entry->title, '/devotional/read/'.$today, 'dde_daily');
    }

    public function sundaySchool(): ?PushCampaign
    {
        $sunday = now(config('uec.timezone'))->next('Sunday')->toDateString();
        $week = SsPlanWeek::query()->whereDate('date', $sunday)->with('lesson')->first();
        if (! $week || $this->alreadySent('ssm_reminder')) {
            return null;
        }

        // The app computes "this week's lesson" itself from the plan, so its SSM home is the right target.
        return $this->push->broadcast('Sunday School tomorrow', $week->description, '/sunday-school', 'ssm_reminder');
    }

    private function alreadySent(string $type): bool
    {
        return PushCampaign::query()->where('type', $type)->where('source', 'system')
            ->where('created_at', '>=', now(config('uec.timezone'))->startOfDay()->utc())
            ->exists();
    }
}
