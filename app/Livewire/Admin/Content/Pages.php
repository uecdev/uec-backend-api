<?php

namespace App\Livewire\Admin\Content;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\Page;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Pages')]
class Pages extends Component
{
    use AdminComponent;

    private const LABELS = [
        'about' => 'About UEC', 'anthem' => 'UEC Anthem', 'doctrine' => 'UEC Doctrine',
        'terms' => 'Terms of service', 'privacy' => 'Privacy policy',
    ];

    #[Url]
    public string $slug = 'about';

    public string $title = '';

    public string $body = '';

    public bool $published = false;

    public function mount(): void
    {
        $this->authorizeAbility('content.view');
        $this->load();
    }

    public function updatedSlug(): void
    {
        $this->resetValidation();
        $this->load();
    }

    private function load(): void
    {
        $this->slug = in_array($this->slug, config('uec.content.pages'), true) ? $this->slug : 'about';
        $page = Page::query()->find($this->slug);
        $this->title = $page?->title ?? self::LABELS[$this->slug];
        $this->body = (string) $page?->body;
        $this->published = $page?->published_at !== null;
    }

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate([
            'slug' => ['required', Rule::in(config('uec.content.pages'))],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:100000'],
        ]);

        $page = Page::query()->findOrNew($data['slug']);
        $page->fill([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'body' => str_replace("\r\n", "\n", $data['body']),
            'updated_by' => auth()->id(),
            'published_at' => $this->published ? ($page->published_at ?? now()) : null,
        ])->save();

        $this->audit('page.updated', $page, $page->title);
        $this->toast('Page saved.');
    }

    public function render()
    {
        return view('livewire.admin.content.pages', [
            'labels' => self::LABELS,
            'pages' => Page::query()->with('editor')->get()->keyBy('slug'),
        ]);
    }
}
