<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Events')]
class Events extends Component
{
    use AdminComponent, WithDataTable;

    #[Url]
    public ?int $filterYear = null;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $start_date = '';

    public string $end_date = '';

    public string $start_time = '';

    public string $location = '';

    public string $category = '';

    public string $description = '';

    public string $share_url = '';

    public bool $published = true;

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
        $this->filterYear ??= (int) now(config('uec.timezone'))->year;
    }

    protected function sortable(): array
    {
        return ['start_date', 'title'];
    }

    protected function defaultSort(): string
    {
        return 'start_date';
    }

    public function create(): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $this->reset('editingId', 'title', 'start_date', 'end_date', 'start_time', 'location', 'category', 'description', 'share_url');
        $this->published = true;
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $e = Event::query()->findOrFail($id);
        $this->editingId = $e->id;
        $this->title = $e->title;
        $this->start_date = $e->start_date->format('Y-m-d');
        $this->end_date = $e->end_date?->format('Y-m-d') ?? '';
        $this->start_time = (string) $e->start_time;
        $this->location = (string) $e->location;
        $this->category = (string) $e->category;
        $this->description = (string) $e->description;
        $this->share_url = (string) $e->share_url;
        $this->published = $e->published_at !== null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:5000'],
            'share_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        $event = $this->editingId ? Event::query()->findOrFail($this->editingId) : new Event;
        $event->fill(array_map(fn ($v) => $v === '' ? null : $v, $data) + [
            'published_at' => $this->published ? ($event->published_at ?? now()) : null,
        ])->save();

        $this->audit($this->editingId ? 'event.updated' : 'event.created', $event, $event->title);
        $this->showForm = false;
        $this->toast('Event saved.');
    }

    public function delete(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $event = Event::query()->findOrFail($id);
        $this->audit('event.deleted', $event, $event->title);
        $event->delete();
        $this->toast('Event deleted.');
    }

    public function render()
    {
        $events = Event::query()->search($this->search)
            ->when($this->filterYear, fn ($q) => $q->whereYear('start_date', $this->filterYear))
            ->orderBy($this->sortColumn(), $this->sortField ? $this->sortDir() : 'asc')
            ->paginate($this->perPage);

        return view('livewire.admin.content.events', ['events' => $events]);
    }
}
