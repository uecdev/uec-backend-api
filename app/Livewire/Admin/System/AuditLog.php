<?php

namespace App\Livewire\Admin\System;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\AuditLog as AuditEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Audit log')]
class AuditLog extends Component
{
    use AdminComponent, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $area = '';

    public ?string $expanded = null;

    public function mount(): void
    {
        $this->authorizeAbility('system.audit');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'area'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $entries = AuditEntry::query()->with('actor')
            ->when($this->area !== '', fn ($q) => $q->where('action', 'like', $this->area.'.%'))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('description', "%{$this->search}%")
                ->orWhereLike('action', "%{$this->search}%")
                ->orWhere('subject_id', $this->search)
                ->orWhereHas('actor', fn ($a) => $a->search($this->search))))
            ->latest('created_at')
            ->paginate(30);

        return view('livewire.admin.system.audit-log', [
            'entries' => $entries,
            'areas' => ['security' => 'Security events', 'admin' => 'Admin sign-ins & access', 'user' => 'Member management', 'payment' => 'Payments', 'subscription' => 'Subscriptions',
                'hymn' => 'Hymns', 'devotional_entry' => 'Devotional entries', 'ss_lesson' => 'Sunday School lessons', 'event' => 'Events', 'page' => 'Pages',
                'push' => 'Push notifications', 'app_settings' => 'App configuration', 'plan' => 'Plans', 'report' => 'Report exports', 'auth' => 'Member auth', 'account' => 'Account changes'],
        ]);
    }
}
