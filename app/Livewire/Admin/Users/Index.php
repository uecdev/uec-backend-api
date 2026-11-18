<?php

namespace App\Livewire\Admin\Users;

use App\Enums\SubscriptionStatus;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Members')]
class Index extends Component
{
    use AdminComponent, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterPremium = '';

    #[Url(except: '')]
    public string $filterPlatform = '';

    public function mount(): void
    {
        $this->authorizeAbility('users.view');
    }

    protected function sortable(): array
    {
        return ['name', 'created_at', 'last_seen_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    public function render()
    {
        $premiumScope = fn (Builder $q) => $q->where(fn ($s) => $s->where('status', SubscriptionStatus::Active->value)
            ->orWhere(fn ($c) => $c->where('status', SubscriptionStatus::Cancelled->value)->where('access_until', '>', now()))
            ->orWhere('status', SubscriptionStatus::PastDue->value));

        $users = User::query()
            ->when($this->filterStatus === 'deleted', fn ($q) => $q->onlyTrashed(), fn ($q) => $q->members())
            ->search($this->search)
            ->when($this->filterStatus === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($this->filterStatus === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when($this->filterPremium === 'yes', fn ($q) => $q->whereHas('subscriptions', $premiumScope))
            ->when($this->filterPremium === 'no', fn ($q) => $q->whereDoesntHave('subscriptions', $premiumScope))
            ->when($this->filterPlatform !== '', fn ($q) => $q->where('last_platform', $this->filterPlatform))
            ->withExists(['subscriptions as is_premium' => $premiumScope])
            ->withCount('devices')
            ->orderBy($this->sortColumn(), $this->sortDir())
            ->paginate($this->perPage);

        return view('livewire.admin.users.index', ['users' => $users]);
    }
}
