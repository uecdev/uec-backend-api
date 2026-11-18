<?php

namespace App\Services\Analytics;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Device;
use App\Models\DevotionalRead;
use App\Models\Feedback;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PushCampaign;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserActivityDay;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Executive metrics computed from first-party data only. Every figure is a
 * direct measurement except where explicitly marked as an estimate (MRR).
 * App-store install counts are not available: no store analytics integration.
 */
class DashboardMetrics
{
    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('uec.timezone');
    }

    /** @return array<string, mixed> */
    public function summary(int $days): array
    {
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;

        return Cache::remember("dashboard:summary:{$days}", 300, fn () => $this->compute($days));
    }

    private function compute(int $days): array
    {
        $end = now($this->tz)->endOfDay();
        $start = now($this->tz)->subDays($days - 1)->startOfDay();
        $prevStart = $start->copy()->subDays($days);
        [$startUtc, $endUtc, $prevStartUtc] = [$start->copy()->utc(), $end->copy()->utc(), $prevStart->copy()->utc()];
        $today = now($this->tz)->toDateString();

        $members = User::query()->members();
        $newUsers = (clone $members)->whereBetween('created_at', [$startUtc, $endUtc])->count();
        $prevNewUsers = (clone $members)->whereBetween('created_at', [$prevStartUtc, $startUtc])->count();

        $revenue = (int) Payment::query()->where('status', PaymentStatus::Success->value)->whereBetween('paid_at', [$startUtc, $endUtc])->sum('amount_kobo');
        $prevRevenue = (int) Payment::query()->where('status', PaymentStatus::Success->value)->whereBetween('paid_at', [$prevStartUtc, $startUtc])->sum('amount_kobo');

        return [
            'range' => ['days' => $days, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'users' => [
                'total' => (clone $members)->count(),
                'new' => $newUsers,
                'new_trend' => $this->trend($newUsers, $prevNewUsers),
                'dau' => UserActivityDay::query()->where('date', $today)->count(),
                'wau' => $this->activeBetween(now($this->tz)->subDays(6)->toDateString(), $today),
                'mau' => $this->activeBetween(now($this->tz)->subDays(29)->toDateString(), $today),
                'suspended' => User::query()->whereNotNull('suspended_at')->count(),
                'retention_d7' => $this->retention(7),
                'retention_d30' => $this->retention(30),
            ],
            'devices' => [
                'registered' => Device::query()->count(),
                'by_platform' => Device::query()->select('platform', DB::raw('count(*) as total'))->groupBy('platform')->pluck('total', 'platform')->all(),
                'active_by_platform' => UserActivityDay::query()->whereBetween('date', [$start->toDateString(), $today])
                    ->whereNotNull('platform')->select('platform', DB::raw('count(distinct user_id) as total'))->groupBy('platform')->pluck('total', 'platform')->all(),
                'by_version' => Device::query()->whereNotNull('app_version')->select('app_version', DB::raw('count(*) as total'))
                    ->groupBy('app_version')->orderByDesc('total')->limit(8)->pluck('total', 'app_version')->all(),
            ],
            'revenue' => [
                'total' => $revenue,
                'trend' => $this->trend($revenue, $prevRevenue),
                'by_status' => Payment::query()->whereBetween('created_at', [$startUtc, $endUtc])
                    ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all(),
                'providers' => $this->providerPerformance($startUtc, $endUtc),
            ],
            'subscriptions' => $this->subscriptions(),
            'notifications' => [
                'campaigns' => PushCampaign::query()->whereBetween('created_at', [$startUtc, $endUtc])->count(),
                'push_success' => (int) PushCampaign::query()->whereBetween('created_at', [$startUtc, $endUtc])->sum('success_count'),
                'push_failure' => (int) PushCampaign::query()->whereBetween('created_at', [$startUtc, $endUtc])->sum('failure_count'),
                'by_channel' => NotificationLog::query()->whereBetween('created_at', [$startUtc, $endUtc])
                    ->select('channel', 'status', DB::raw('count(*) as total'))->groupBy('channel', 'status')->get()
                    ->groupBy('channel')->map(fn ($rows) => $rows->pluck('total', 'status')->all())->all(),
            ],
            'engagement' => [
                'devotional_reads' => DevotionalRead::query()->whereBetween('read_at', [$startUtc, $endUtc])->count(),
                'readers' => DevotionalRead::query()->whereBetween('read_at', [$startUtc, $endUtc])->distinct('user_id')->count('user_id'),
                'favourites' => DB::table('favourites')->count(),
                'programs' => DB::table('programs')->count(),
                'top_hymns' => DB::table('favourites')->join('hymns', 'hymns.id', '=', 'favourites.favouritable_id')
                    ->where('favouritable_type', 'hymn')->select('hymns.number', 'hymns.title', DB::raw('count(*) as total'))
                    ->groupBy('hymns.number', 'hymns.title')->orderByDesc('total')->limit(5)->get()->map(fn ($r) => (array) $r)->all(),
            ],
            'feedback_open' => Feedback::query()->whereIn('status', ['new', 'in_review'])->count(),
            'series' => $this->series($start, $end),
        ];
    }

    private function activeBetween(string $from, string $to): int
    {
        return UserActivityDay::query()->whereBetween('date', [$from, $to])->distinct('user_id')->count('user_id');
    }

    /**
     * Day-N retention: of members who registered between N and 2N days ago,
     * the share with any activity N or more days after registering.
     */
    private function retention(int $n): ?float
    {
        $cohort = User::query()->members()
            ->whereBetween('created_at', [now()->subDays($n * 2), now()->subDays($n)])
            ->pluck('created_at', 'id');
        if ($cohort->isEmpty()) {
            return null;
        }

        $retained = 0;
        foreach ($cohort->chunk(500) as $chunk) {
            $activity = UserActivityDay::query()->whereIn('user_id', $chunk->keys())->select('user_id', DB::raw('max(date) as last_date'))->groupBy('user_id')->pluck('last_date', 'user_id');
            foreach ($chunk as $id => $created) {
                $last = $activity[$id] ?? null;
                if ($last && Carbon::parse($last)->gte(Carbon::parse($created)->addDays($n)->startOfDay())) {
                    $retained++;
                }
            }
        }

        return round($retained / $cohort->count() * 100, 1);
    }

    private function providerPerformance(Carbon $from, Carbon $to): array
    {
        return Payment::query()->whereBetween('created_at', [$from, $to])
            ->select('provider', 'status', DB::raw('count(*) as total'), DB::raw('sum(amount_kobo) as amount'))
            ->groupBy('provider', 'status')->get()->groupBy('provider')
            ->map(function ($rows, $provider) {
                $by = $rows->keyBy(fn ($r) => $r->status instanceof PaymentStatus ? $r->status->value : $r->status);
                $success = (int) ($by['success']->total ?? 0);
                $attempts = $rows->sum('total');

                return [
                    'provider' => $provider,
                    'attempts' => (int) $attempts,
                    'success' => $success,
                    'failed' => (int) ($by['failed']->total ?? 0),
                    'abandoned' => (int) ($by['abandoned']->total ?? 0),
                    'success_rate' => $attempts ? round($success / $attempts * 100, 1) : null,
                    'revenue' => (int) ($by['success']->amount ?? 0),
                ];
            })->values()->all();
    }

    private function subscriptions(): array
    {
        $graceCutoff = now()->subDays(config('uec.subscriptions.grace_days'));
        $live = Subscription::query()->with('plan')
            ->where(fn ($q) => $q->where('status', SubscriptionStatus::Active->value)
                ->orWhere(fn ($c) => $c->where('status', SubscriptionStatus::Cancelled->value)->where('access_until', '>', now()))
                ->orWhere(fn ($p) => $p->where('status', SubscriptionStatus::PastDue->value)->where('past_due_at', '>', $graceCutoff)))
            ->get();

        $autoRenewing = $live->where('status', SubscriptionStatus::Active);

        return [
            'premium_users' => $live->unique('user_id')->count(),
            'auto_renewing' => $autoRenewing->count(),
            'cancelled_with_access' => $live->where('status', SubscriptionStatus::Cancelled)->count(),
            'past_due' => $live->where('status', SubscriptionStatus::PastDue)->count(),
            'by_plan' => $live->groupBy(fn ($s) => $s->plan?->name ?? $s->plan_id)->map->count()->all(),
            // Estimate: normalised monthly value of auto-renewing subscriptions.
            'mrr_estimate' => (int) round($autoRenewing->sum(fn ($s) => $s->plan ? $s->plan->amount_kobo / max(1, $s->plan->interval_months) : 0)),
            'churned_30d' => Subscription::query()->where('status', SubscriptionStatus::Expired->value)->where('expired_at', '>=', now()->subDays(30))->count(),
        ];
    }

    private function series(Carbon $start, Carbon $end): array
    {
        $labels = [];
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $day) {
            $labels[] = $day->toDateString();
        }

        $tzSql = "AT TIME ZONE 'UTC' AT TIME ZONE '{$this->tz}'";
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        $active = UserActivityDay::query()->whereBetween('date', [$labels[0], end($labels)])
            ->select(DB::raw('date::text as d'), DB::raw('count(*) as total'))->groupBy('date')->pluck('total', 'd');
        $registrations = User::query()->members()->whereBetween('created_at', [$startUtc, $endUtc])
            ->select(DB::raw("date(created_at {$tzSql})::text as d"), DB::raw('count(*) as total'))->groupBy('d')->pluck('total', 'd');
        $revenue = Payment::query()->where('status', PaymentStatus::Success->value)->whereBetween('paid_at', [$startUtc, $endUtc])
            ->select(DB::raw("date(paid_at {$tzSql})::text as d"), DB::raw('sum(amount_kobo) as total'))->groupBy('d')->pluck('total', 'd');
        $reads = DevotionalRead::query()->whereBetween('read_at', [$startUtc, $endUtc])
            ->select(DB::raw("date(read_at {$tzSql})::text as d"), DB::raw('count(*) as total'))->groupBy('d')->pluck('total', 'd');

        $fill = fn ($map, $divisor = 1) => array_map(fn ($d) => round(((float) ($map[$d] ?? 0)) / $divisor, 2), $labels);

        return [
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('M j'), $labels),
            'active_users' => $fill($active),
            'registrations' => $fill($registrations),
            'revenue' => $fill($revenue, 100), // naira
            'devotional_reads' => $fill($reads),
        ];
    }

    private function trend(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
