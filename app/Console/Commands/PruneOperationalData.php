<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\RefreshToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('uec:prune {--log-days=90 : Keep integration and notification logs this many days}')]
#[Description('Prune expired tokens, stale checkouts and old operational logs')]
class PruneOperationalData extends Command
{
    public function handle(): int
    {
        $days = max(7, (int) $this->option('log-days'));

        $counts = [
            'expired access tokens' => PersonalAccessToken::query()->where('expires_at', '<', now()->subDay())->delete(),
            'dead refresh tokens' => RefreshToken::query()->where(fn ($q) => $q->where('expires_at', '<', now()->subDays(7))
                ->orWhere('revoked_at', '<', now()->subDays(30)))->delete(),
            'abandoned checkouts' => Payment::query()->where('status', PaymentStatus::Pending->value)
                ->where('expires_at', '<', now()->subDay())->update(['status' => PaymentStatus::Abandoned->value, 'updated_at' => now()]),
            'integration logs' => DB::table('integration_logs')->where('created_at', '<', now()->subDays($days))->delete(),
            'notification logs' => DB::table('notification_logs')->where('created_at', '<', now()->subDays($days))->delete(),
            'processed webhooks' => DB::table('webhook_events')->whereIn('status', ['processed', 'ignored'])->where('created_at', '<', now()->subDays(180))->delete(),
        ];

        foreach ($counts as $label => $n) {
            $this->line(sprintf('%-24s %d', $label, $n));
        }

        return self::SUCCESS;
    }
}
