<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\BibleVersion;
use App\Support\Usfm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Bible')]
class Bible extends Component
{
    use AdminComponent;

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
    }

    public function toggle(string $id): void
    {
        $this->authorizeAbility('content.manage');
        $version = BibleVersion::query()->findOrFail($id);
        $version->forceFill(['is_active' => ! $version->is_active])->save();
        Cache::forever('bible:rev', (int) Cache::get('bible:rev', 1) + 1);
        $this->audit($version->is_active ? 'bible_version.enabled' : 'bible_version.disabled', $version, $version->name);
        $this->toast($version->is_active ? "{$version->id} is now available in the app." : "{$version->id} is hidden from the app.");
    }

    public function render()
    {
        $counts = DB::table('bible_verses')->select('version_id', DB::raw('count(*) as verses'), DB::raw('count(distinct book_id) as books'))
            ->groupBy('version_id')->get()->keyBy('version_id');

        return view('livewire.admin.content.bible', [
            'versions' => BibleVersion::query()->orderBy('sort')->orderBy('id')->get(),
            'counts' => $counts,
            'bookCount' => count(Usfm::BOOKS),
        ]);
    }
}
