<?php

namespace App\Livewire\Admin\Finance;

use App\Enums\PaymentStatus;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
#[Title('Payments')]
class Payments extends Component
{
    use AdminComponent, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterProvider = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public ?string $selectedId = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        $this->authorizeAbility('finance.view');
    }

    protected function sortable(): array
    {
        return ['created_at', 'amount_kobo', 'paid_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    private function query(): Builder
    {
        $tz = config('uec.timezone');

        return Payment::query()->with(['user', 'plan'])
            ->search($this->search)
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterProvider !== '', fn ($q) => $q->where('provider', $this->filterProvider))
            ->when($this->filterFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->filterFrom, $tz)->startOfDay()->utc()))
            ->when($this->filterTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->filterTo, $tz)->endOfDay()->utc()));
    }

    public function show(string $id): void
    {
        $this->selectedId = $id;
        $this->showDetail = true;
    }

    /** Reconciliation: ask the gateway for the authoritative status and apply it idempotently. */
    public function reverify(string $id, PaymentService $payments): void
    {
        $this->authorizeAbility('finance.manage');
        $payment = Payment::query()->findOrFail($id);
        $before = $payment->status;
        $payment->forceFill(['last_verified_at' => null])->save();
        $payment = $payments->refreshFromGateway($payment);
        $this->audit('payment.reverified', $payment, "{$before->value} → {$payment->status->value}");
        $this->toast($before === $payment->status ? 'Gateway confirms the status is unchanged.' : "Status updated to {$payment->status->value}.");
    }

    public function reverifyStale(PaymentService $payments): void
    {
        $this->authorizeAbility('finance.manage');
        $stale = Payment::query()->where('status', PaymentStatus::Pending->value)->where('created_at', '<', now()->subMinutes(30))->limit(50)->get();
        $changed = 0;
        foreach ($stale as $payment) {
            $payment->forceFill(['last_verified_at' => null])->save();
            if ($payments->refreshFromGateway($payment)->status !== PaymentStatus::Pending) {
                $changed++;
            }
        }
        $this->audit('payments.bulk_reverified', null, "{$stale->count()} checked, {$changed} updated");
        $this->toast("{$stale->count()} pending payments checked; {$changed} updated.");
    }

    public function markRefunded(string $id, PaymentService $payments): void
    {
        $this->authorizeAbility('finance.manage');
        $payment = Payment::query()->findOrFail($id);
        abort_unless($payment->status === PaymentStatus::Success, 422);
        $payments->refund($payment, 'admin', ['by' => auth()->id()]);
        $this->audit('payment.marked_refunded', $payment, 'Recorded manually; premium access revoked');
        $this->toast('Payment marked as refunded and access revoked.');
    }

    public function export(): StreamedResponse
    {
        $this->authorizeAbility('reports.export');
        $this->audit('report.exported', null, 'Payments CSV', array_filter(['status' => $this->filterStatus, 'provider' => $this->filterProvider, 'from' => $this->filterFrom, 'to' => $this->filterTo]));
        $query = $this->query()->orderByDesc('created_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Created (Lagos)', 'Paid (Lagos)', 'Member', 'Plan', 'Purpose', 'Provider', 'Amount (NGN)', 'Currency', 'Status', 'Channel', 'Gateway transaction']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, [
                        $p->reference, $p->created_at->timezone(config('uec.timezone'))->toDateTimeString(), $p->paid_at?->timezone(config('uec.timezone'))->toDateTimeString(),
                        $p->user?->id, $p->plan?->name, $p->purpose, $p->provider, number_format($p->amount_kobo / 100, 2, '.', ''), $p->currency,
                        $p->status->value, $p->channel, $p->provider_transaction_id,
                    ]);
                }
            });
            fclose($out);
        }, 'uec-payments-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $base = fn () => Payment::query();
        $selected = $this->selectedId ? Payment::query()->with(['user', 'plan', 'subscription'])->find($this->selectedId) : null;

        return view('livewire.admin.finance.payments', [
            'payments' => $this->query()->orderBy($this->sortColumn(), $this->sortDir())->paginate($this->perPage),
            'summary' => [
                'success_30d' => (int) $base()->where('status', 'success')->where('paid_at', '>=', now()->subDays(30))->sum('amount_kobo'),
                'count_30d' => $base()->where('status', 'success')->where('paid_at', '>=', now()->subDays(30))->count(),
                'failed_30d' => $base()->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
                'stale_pending' => $base()->where('status', 'pending')->where('created_at', '<', now()->subMinutes(30))->count(),
                'refunded_30d' => $base()->where('status', 'refunded')->where('refunded_at', '>=', now()->subDays(30))->count(),
            ],
            'selected' => $selected,
            'webhooks' => $selected ? WebhookEvent::query()->where('reference', $selected->reference)->latest()->get() : collect(),
        ]);
    }
}
