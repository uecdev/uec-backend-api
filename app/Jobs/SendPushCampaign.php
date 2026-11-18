<?php

namespace App\Jobs;

use App\Enums\PushCampaignStatus;
use App\Models\PushCampaign;
use App\Services\Notifications\PushCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1; // partial sends must not be repeated

    public int $timeout = 1800;

    public function __construct(public readonly string $campaignId) {}

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    public function handle(PushCampaignService $service): void
    {
        $campaign = PushCampaign::query()->find($this->campaignId);
        if ($campaign && $campaign->status === PushCampaignStatus::Sending) {
            $service->send($campaign);
        }
    }
}
