<?php

namespace App\Livewire\Admin\Feedback;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Feedback;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Feedback')]
class Index extends Component
{
    use AdminComponent, WithDataTable;

    #[Url(except: 'open')]
    public string $filterStatus = 'open';

    #[Url(except: '')]
    public string $filterType = '';

    public ?string $selectedId = null;

    public bool $showDetail = false;

    public string $status = 'new';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorizeAbility('feedback.view');
    }

    protected function sortable(): array
    {
        return ['created_at', 'type'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    public function open(string $id): void
    {
        $f = Feedback::query()->findOrFail($id);
        $this->selectedId = $f->id;
        $this->status = $f->status;
        $this->notes = (string) $f->admin_notes;
        $this->showDetail = true;
    }

    public function saveTriage(): void
    {
        $this->authorizeAbility('feedback.manage');
        $data = $this->validate(['status' => ['required', Rule::in(Feedback::STATUSES)], 'notes' => ['nullable', 'string', 'max:5000']]);
        $f = Feedback::query()->findOrFail($this->selectedId);
        $f->forceFill([
            'status' => $data['status'],
            'admin_notes' => $data['notes'] ?: null,
            'handled_by' => auth()->id(),
            'resolved_at' => $data['status'] === 'resolved' ? ($f->resolved_at ?? now()) : null,
        ])->save();
        $this->audit('feedback.triaged', $f, $data['status']);
        $this->showDetail = false;
        $this->toast('Feedback updated.');
    }

    public function render()
    {
        $items = Feedback::query()->with(['user', 'attachments'])
            ->when($this->search !== '', fn ($q) => $q->whereLike('message', "%{$this->search}%"))
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereIn('status', ['new', 'in_review']))
            ->when(in_array($this->filterStatus, Feedback::STATUSES, true), fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterType !== '', fn ($q) => $q->where('type', $this->filterType))
            ->orderBy($this->sortColumn(), $this->sortDir())
            ->paginate($this->perPage);

        return view('livewire.admin.feedback.index', [
            'items' => $items,
            'types' => Feedback::query()->distinct()->orderBy('type')->pluck('type'),
            'selected' => $this->selectedId ? Feedback::query()->with(['user', 'attachments', 'handler'])->find($this->selectedId) : null,
        ]);
    }
}
