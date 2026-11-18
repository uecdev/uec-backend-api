<?php

namespace App\Services\Analytics;

use App\Enums\PaymentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operational and executive reports with date filters. Every report is a direct
 * query over first-party tables (no sampling, no estimates). Dates are Africa/Lagos.
 */
class ReportBuilder
{
    public const REPORTS = [
        'registrations' => ['label' => 'Registrations by day', 'description' => 'New member accounts per day and platform of first activity.'],
        'activity' => ['label' => 'Active members by day', 'description' => 'Distinct signed-in members using the app each day, by platform.'],
        'revenue' => ['label' => 'Revenue by day', 'description' => 'Successful payments per day and provider (NGN).'],
        'payments' => ['label' => 'Payment ledger', 'description' => 'Every payment attempt with status and gateway reference.'],
        'subscriptions' => ['label' => 'Subscription movements', 'description' => 'Subscriptions started, cancelled and expired per day.'],
        'engagement' => ['label' => 'Devotional engagement', 'description' => 'Daily Delight readings marked read per day.'],
        'notifications' => ['label' => 'Notification delivery', 'description' => 'Email, WhatsApp and push deliveries per day and outcome.'],
        'feedback' => ['label' => 'Feedback received', 'description' => 'Feedback submissions by type and status.'],
    ];

    /** @return array{columns: list<string>, rows: list<array>, total_rows: int} */
    public function build(string $report, string $from, string $to, ?int $limit = null): array
    {
        $tz = (string) config('uec.timezone');
        $start = Carbon::parse($from, $tz)->startOfDay()->utc();
        $end = Carbon::parse($to, $tz)->endOfDay()->utc();
        $local = fn (string $col) => "date({$col} AT TIME ZONE 'UTC' AT TIME ZONE '{$tz}')";

        [$columns, $query] = match ($report) {
            'registrations' => [['Date', 'New members', 'Email sign-ups', 'Phone sign-ups', 'Google sign-ups'], DB::table('users')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("{$local('created_at')} as day, count(*) as total,
                    count(*) filter (where email is not null and google_sub is null) as email,
                    count(*) filter (where phone is not null and email is null) as phone,
                    count(*) filter (where google_sub is not null) as google")
                ->groupBy('day')->orderBy('day')],
            'activity' => [['Date', 'Active members', 'Android', 'iOS'], DB::table('user_activity_days')
                ->whereBetween('date', [$from, $to])
                ->selectRaw("date as day, count(distinct user_id) as total,
                    count(distinct user_id) filter (where platform = 'android') as android,
                    count(distinct user_id) filter (where platform = 'ios') as ios")
                ->groupBy('date')->orderBy('date')],
            'revenue' => [['Date', 'Provider', 'Payments', 'Revenue (NGN)'], DB::table('payments')
                ->where('status', PaymentStatus::Success->value)->whereBetween('paid_at', [$start, $end])
                ->selectRaw("{$local('paid_at')} as day, provider, count(*) as total, round(sum(amount_kobo) / 100.0, 2) as revenue")
                ->groupBy('day', 'provider')->orderBy('day')],
            'payments' => [['Created', 'Reference', 'Provider', 'Purpose', 'Plan', 'Amount (NGN)', 'Status', 'Paid at', 'Gateway reference'], DB::table('payments')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("to_char(created_at AT TIME ZONE 'UTC' AT TIME ZONE '{$tz}', 'YYYY-MM-DD HH24:MI') as created, reference, provider, purpose, plan_id,
                    round(amount_kobo / 100.0, 2) as amount, status, to_char(paid_at AT TIME ZONE 'UTC' AT TIME ZONE '{$tz}', 'YYYY-MM-DD HH24:MI') as paid, provider_transaction_id")
                ->orderBy('created_at')],
            'subscriptions' => [['Date', 'Started', 'Cancelled', 'Expired'], DB::query()->fromSub(
                DB::table('subscriptions')->whereBetween('started_at', [$start, $end])->selectRaw("{$local('started_at')} as day, 1 as started, 0 as cancelled, 0 as expired")
                    ->unionAll(DB::table('subscriptions')->whereBetween('cancelled_at', [$start, $end])->selectRaw("{$local('cancelled_at')} as day, 0, 1, 0"))
                    ->unionAll(DB::table('subscriptions')->whereBetween('expired_at', [$start, $end])->selectRaw("{$local('expired_at')} as day, 0, 0, 1")),
                'm')->selectRaw('day, sum(started) as started, sum(cancelled) as cancelled, sum(expired) as expired')->groupBy('day')->orderBy('day')],
            'engagement' => [['Date', 'Readings marked read', 'Distinct readers'], DB::table('devotional_reads')
                ->whereBetween('read_at', [$start, $end])
                ->selectRaw("{$local('read_at')} as day, count(*) as total, count(distinct user_id) as readers")
                ->groupBy('day')->orderBy('day')],
            'notifications' => [['Date', 'Channel', 'Status', 'Count'], DB::table('notification_logs')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("{$local('created_at')} as day, channel, status, count(*) as total")
                ->groupBy('day', 'channel', 'status')->orderBy('day')],
            'feedback' => [['Type', 'Status', 'Count'], DB::table('feedback')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->orderBy('type')],
        };

        $total = (clone $query)->getCountForPagination();
        $rows = ($limit ? $query->limit($limit) : $query)->get()
            ->map(fn ($r) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, (array) $r))
            ->all();

        return ['columns' => $columns, 'rows' => $rows, 'total_rows' => $total];
    }
}
