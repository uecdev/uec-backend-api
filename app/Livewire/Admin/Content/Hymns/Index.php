<?php

namespace App\Livewire\Admin\Content\Hymns;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Livewire\Admin\Concerns\WithDataTable;
use App\Models\Hymn;
use App\Models\HymnCategory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Hymnal')]
class Index extends Component
{
    use AdminComponent, WithDataTable;

    #[Url(except: '')]
    public string $filterCategory = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
    }

    protected function sortable(): array
    {
        return ['number', 'title', 'updated_at'];
    }

    protected function defaultSort(): string
    {
        return 'number';
    }

    public function togglePublished(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $hymn = Hymn::query()->findOrFail($id);
        $hymn->forceFill(['published_at' => $hymn->published_at ? null : now()])->save();
        ContentPublished::dispatch('hymns');
        $this->audit($hymn->published_at ? 'hymn.published' : 'hymn.unpublished', $hymn, "#{$hymn->number} {$hymn->title}");
        $this->toast($hymn->published_at ? 'Hymn published.' : 'Hymn hidden from the app.');
    }

    public function delete(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $hymn = Hymn::query()->findOrFail($id);
        $this->audit('hymn.deleted', $hymn, "#{$hymn->number} {$hymn->title}");
        $hymn->delete();
        ContentPublished::dispatch('hymns');
        $this->toast('Hymn deleted.');
    }

    public function render()
    {
        $hymns = Hymn::query()
            ->search($this->search)
            ->when($this->filterCategory !== '', fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('slug', $this->filterCategory)))
            ->when($this->filterStatus === 'published', fn ($q) => $q->published())
            ->when($this->filterStatus === 'draft', fn ($q) => $q->whereNull('published_at'))
            ->with('categories:id,slug,name')
            ->withCount(['audio', 'stanzas'])
            ->withExists('solfa')
            ->orderBy($this->sortColumn(), $this->sortField ? $this->sortDir() : 'asc')
            ->paginate($this->perPage);

        return view('livewire.admin.content.hymns.index', [
            'hymns' => $hymns,
            'categories' => HymnCategory::query()->orderBy('sort')->get(),
            'totals' => ['all' => Hymn::query()->count(), 'published' => Hymn::query()->published()->count()],
        ]);
    }
}
