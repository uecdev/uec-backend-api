<?php

namespace App\Livewire\Admin\Finance;

use App\Enums\SubscriptionStatus;
use App\Jobs\SyncSubscriptionWithGateway;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Subscriptions')]
class Subscriptions extends Component
{
    use AdminComponent, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterPlan = '';

    #[Url(except: '')]
    public string $filterProvider = '';

    public function mount(): void
    {
        $this->authorizeAbility('finance.view');
    }

    protected function sortable(): array
    {
        return ['created_at', 'current_period_end'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    public function cancel(string $id, SubscriptionService $subscriptions): void
    {
        $this->authorizeAbility('finance.manage');
        $subscription = Subscription::query()->findOrFail($id);
        $subscriptions->cancel($subscription, 'Cancelled by an administrator', 'admin');
        $this->toast('Auto-renewal stopped. Access continues until the end of the paid period.');
    }

    public function resync(string $id): void
    {
        $this->authorizeAbility('finance.manage');
        $subscription = Subscription::query()->findOrFail($id);
        $operation = $subscription->status === SubscriptionStatus::Cancelled ? 'cancel' : 'schedule_renewal';
        SyncSubscriptionWithGateway::dispatch($subscription->id, $operation);
        $this->audit('subscription.resync_requested', $subscription, $operation);
        $this->toast('Gateway sync queued.');
    }

    public function render()
    {
        $subscriptions = Subscription::query()->with(['user', 'plan', 'pendingPlan'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->search($this->search))->orWhere('provider_subscription_code', $this->search)->orWhere('id', $this->search)))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterPlan !== '', fn ($q) => $q->where('plan_id', $this->filterPlan))
            ->when($this->filterProvider !== '', fn ($q) => $q->where('provider', $this->filterProvider))
            ->orderBy($this->sortColumn(), $this->sortDir())
            ->paginate($this->perPage);

        return view('livewire.admin.finance.subscriptions', [
            'subscriptions' => $subscriptions,
            'plans' => Plan::query()->orderBy('sort')->get(),
            'unsynced' => Subscription::query()->whereNull('gateway_synced_at')->whereIn('status', ['cancelled', 'active'])->whereNotNull('provider_subscription_code')->count(),
        ]);
    }
}
