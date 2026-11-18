<?php

namespace App\Livewire\Admin\Content\Devotionals;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\DevotionalEdition;
use App\Models\DevotionalEntry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class EntryForm extends Component
{
    use AdminComponent;

    public int $year;

    public ?string $entryId = null;

    public string $date = '';

    public string $title = '';

    public string $passage = '';

    public string $memory_verse_text = '';

    public string $memory_verse_ref = '';

    public string $body = '';

    public string $declaration = '';

    public string $prayer = '';

    public function mount(int $year, ?string $entry = null): void
    {
        $this->authorizeAbility('content.view');
        $this->year = DevotionalEdition::query()->findOrFail($year)->year;

        if ($entry) {
            $model = DevotionalEntry::query()->where('edition_year', $this->year)->findOrFail($entry);
            $this->entryId = $model->id;
            $this->date = $model->date->format('Y-m-d');
            foreach (['title', 'passage', 'memory_verse_text', 'memory_verse_ref', 'body', 'declaration', 'prayer'] as $f) {
                $this->{$f} = (string) $model->{$f};
            }
        } else {
            $requested = (string) request()->query('date', '');
            $this->date = str_starts_with($requested, "{$this->year}-") ? $requested : '';
        }
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'date' => ['required', 'date_format:Y-m-d', 'starts_with:'.$this->year.'-', Rule::unique('devotional_entries', 'date')->ignore($this->entryId)],
            'title' => ['required', 'string', 'max:200'],
            'passage' => ['required', 'string', 'max:120'],
            'memory_verse_text' => ['nullable', 'string', 'max:2000'],
            'memory_verse_ref' => ['nullable', 'string', 'max:120', 'required_with:memory_verse_text'],
            'body' => ['required', 'string', 'max:30000'],
            'declaration' => ['nullable', 'string', 'max:5000'],
            'prayer' => ['nullable', 'string', 'max:5000'],
        ], ['date.starts_with' => "The date must be in {$this->year}.", 'date.unique' => 'There is already a reading for this date.']);

        $entry = $this->entryId ? DevotionalEntry::query()->findOrFail($this->entryId) : new DevotionalEntry(['edition_year' => $this->year]);
        $entry->fill(array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $data))->save();

        ContentPublished::dispatch('devotional', $this->year);
        $this->audit($this->entryId ? 'devotional_entry.updated' : 'devotional_entry.created', $entry, $entry->date->format('Y-m-d').' '.$entry->title);
        session()->flash('toast', ['type' => 'success', 'message' => 'Reading saved.']);
        $this->redirectRoute('admin.devotionals.entries', ['year' => $this->year, 'month' => $entry->date->month], navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.content.devotionals.entry-form')
            ->title($this->entryId ? 'Edit reading' : 'New reading');
    }
}
