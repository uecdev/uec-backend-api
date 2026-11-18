<?php

namespace App\Livewire\Admin\Content\SundaySchool;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\SsManual;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Sunday School')]
class Manuals extends Component
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
        $next = (int) (SsManual::query()->max('year') ?: now()->year) + (SsManual::query()->exists() ? 1 : 0);
        $this->fill(['editingYear' => null, 'year' => $next, 'title' => "{$next} Manual", 'theme' => '', 'is_premium' => false, 'published' => false, 'showForm' => true]);
    }

    public function edit(int $year): void
    {
        $this->authorizeAbility('content.manage');
        $this->resetValidation();
        $m = SsManual::query()->findOrFail($year);
        $this->fill(['editingYear' => $m->year, 'year' => $m->year, 'title' => $m->title, 'theme' => (string) $m->theme, 'is_premium' => $m->is_premium, 'published' => $m->published_at !== null, 'showForm' => true]);
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'year' => ['required', 'integer', 'between:2000,2100', Rule::unique('ss_manuals', 'year')->ignore($this->editingYear, 'year')],
            'title' => ['required', 'string', 'max:120'],
            'theme' => ['nullable', 'string', 'max:200'],
            'is_premium' => ['boolean'],
        ]);

        $manual = $this->editingYear ? SsManual::query()->findOrFail($this->editingYear) : new SsManual;
        $manual->fill([
            'year' => $data['year'], 'title' => $data['title'], 'theme' => $data['theme'] ?: null, 'is_premium' => $data['is_premium'],
            'published_at' => $this->published ? ($manual->published_at ?? now()) : null,
        ])->save();

        ContentPublished::dispatch('sunday_school', $manual->year);
        $this->audit($this->editingYear ? 'ss_manual.updated' : 'ss_manual.created', $manual, $manual->title);
        $this->showForm = false;
        $this->toast('Manual saved.');
    }

    public function render()
    {
        return view('livewire.admin.content.sunday-school.manuals', [
            'manuals' => SsManual::query()->withCount(['lessons', 'planWeeks'])->orderByDesc('year')->get(),
        ]);
    }
}
