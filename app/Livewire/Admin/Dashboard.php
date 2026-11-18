<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Services\Analytics\DashboardMetrics;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    use AdminComponent;

    #[Url]
    public int $days = 30;

    public function mount(): void
    {
        $this->authorizeAbility('dashboard.view');
    }

    public function refresh(): void
    {
        foreach ([7, 30, 90, 365] as $d) {
            Cache::forget("dashboard:summary:{$d}");
        }
        $this->toast('Dashboard refreshed.');
    }

    /** Download the current summary as CSV. */
    public function export(DashboardMetrics $metrics): StreamedResponse
    {
        $this->authorizeAbility('reports.export');
        $m = $metrics->summary($this->days);
        $this->audit('report.exported', null, 'Dashboard summary', ['days' => $this->days]);

        return response()->streamDownload(function () use ($m) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Value', 'Source']);
            $rows = [
                ['Range', $m['range']['from'].' to '.$m['range']['to'], ''],
                ['Total members', $m['users']['total'], 'Measured'],
                ['New registrations', $m['users']['new'], 'Measured'],
                ['Daily active users (today)', $m['users']['dau'], 'Measured (signed-in API activity)'],
                ['Weekly active users', $m['users']['wau'], 'Measured (signed-in API activity)'],
                ['Monthly active users', $m['users']['mau'], 'Measured (signed-in API activity)'],
                ['Day-7 retention %', $m['users']['retention_d7'] ?? 'n/a', 'Measured'],
                ['Day-30 retention %', $m['users']['retention_d30'] ?? 'n/a', 'Measured'],
                ['Registered devices', $m['devices']['registered'], 'Measured (push registrations)'],
                ['Revenue (NGN)', number_format($m['revenue']['total'] / 100, 2, '.', ''), 'Measured'],
                ['Premium users', $m['subscriptions']['premium_users'], 'Measured'],
                ['MRR estimate (NGN)', number_format($m['subscriptions']['mrr_estimate'] / 100, 2, '.', ''), 'Estimate'],
                ['Push messages delivered', $m['notifications']['push_success'], 'Measured (FCM accepted)'],
                ['Devotional reads', $m['engagement']['devotional_reads'], 'Measured'],
                ['App store installs', 'Not available', 'No store analytics integration'],
            ];
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'uec-dashboard-'.$m['range']['to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(DashboardMetrics $metrics)
    {
        return view('livewire.admin.dashboard', ['m' => $metrics->summary($this->days)]);
    }
}
