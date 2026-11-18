<?php

namespace App\Livewire\Admin\Engagement;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Services\Analytics\ReportBuilder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
#[Title('Reports')]
class Reports extends Component
{
    use AdminComponent;

    #[Url]
    public string $report = 'registrations';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorizeAbility('reports.view');
        $tz = config('uec.timezone');
        $this->from = $this->from ?: now($tz)->subDays(29)->toDateString();
        $this->to = $this->to ?: now($tz)->toDateString();
    }

    private function validated(): array
    {
        return $this->validate([
            'report' => ['required', 'in:'.implode(',', array_keys(ReportBuilder::REPORTS))],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    public function export(ReportBuilder $builder): ?StreamedResponse
    {
        $this->authorizeAbility('reports.export');
        $data = $this->validated();
        $result = $builder->build($data['report'], $data['from'], $data['to']);
        $this->audit('report.exported', null, ReportBuilder::REPORTS[$data['report']]['label'], $data);

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $result['columns']);
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, "uec-{$data['report']}-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(ReportBuilder $builder)
    {
        $data = $this->validated();

        return view('livewire.admin.engagement.reports', [
            'reports' => ReportBuilder::REPORTS,
            'result' => $builder->build($data['report'], $data['from'], $data['to'], 500),
        ]);
    }
}
