<?php

namespace App\Livewire\Admin\Content\Devotionals;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\DevotionalEdition;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Daily Delight')]
class Editions extends Component
{
    use AdminComponent;

    public bool $showForm = false;

    public ?int $editingYear = null;

    public ?int $year = null;

    public string $title = '';

    public string $theme = '';

    public bool $is_premium = false;

    public bool $published = false;

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
    }

    public function create(): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $next = (int) (DevotionalEdition::query()->max('year') ?: now()->year) + (DevotionalEdition::query()->exists() ? 1 : 0);
        $this->fill(['editingYear' => null, 'year' => $next, 'title' => "{$next} Edition", 'theme' => '', 'is_premium' => false, 'published' => false, 'showForm' => true]);
    }

    public function edit(int $year): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $e = DevotionalEdition::query()->findOrFail($year);
        $this->fill(['editingYear' => $e->year, 'year' => $e->year, 'title' => $e->title, 'theme' => (string) $e->theme, 'is_premium' => $e->is_premium, 'published' => $e->published_at !== null, 'showForm' => true]);
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'year' => ['required', 'integer', 'between:2000,2100', Rule::unique('devotional_editions', 'year')->ignore($this->editingYear, 'year')],
            'title' => ['required', 'string', 'max:120'],
            'theme' => ['nullable', 'string', 'max:200'],
            'is_premium' => ['boolean'],
        ]);

        $edition = $this->editingYear ? DevotionalEdition::query()->findOrFail($this->editingYear) : new DevotionalEdition;
        $edition->fill([
            'year' => $data['year'],
            'title' => $data['title'],
            'theme' => $data['theme'] ?: null,
            'is_premium' => $data['is_premium'],
            'published_at' => $this->published ? ($edition->published_at ?? now()) : null,
        ])->save();

        ContentPublished::dispatch('devotional', $edition->year);
        $this->audit($this->editingYear ? 'devotional_edition.updated' : 'devotional_edition.created', $edition, $edition->title);
        $this->showForm = false;
        $this->toast('Edition saved.');
    }

    public function render()
    {
        $editions = DevotionalEdition::query()->withCount('entries')->orderByDesc('year')->get();

        return view('livewire.admin.content.devotionals.editions', ['editions' => $editions]);
    }
}
