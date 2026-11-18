<?php

namespace App\Livewire\Admin\Content\Hymns;

use App\Events\ContentPublished;
use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\Hymn;
use App\Models\HymnAudio;
use App\Models\HymnCategory;
use App\Models\HymnSolfa;
use App\Services\Media\MediaUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Form extends Component
{
    use AdminComponent, WithFileUploads;

    public ?string $hymnId = null;

    public ?int $number = null;

    public string $title = '';

    public string $first_line = '';

    public string $author = '';

    public string $composer = '';

    public string $tune = '';

    public string $meter = '';

    public string $key = '';

    public string $tempo = '';

    public string $about = '';

    public string $scripture_refs = '';

    public string $tags = '';

    /** @var list<string> */
    public array $categoryIds = [];

    public bool $published = false;

    /** @var list<array{type: string, number: ?int, lines: string}> */
    public array $stanzas = [];

    public string $solfaFormat = 'none'; // none | text | image

    public string $solfaKey = '';

    public string $solfaTime = '';

    /** @var list<array{label: string, lines: string}> */
    public array $solfaSections = [];

    public $solfaImage = null;

    public ?string $solfaImagePath = null;

    public $newAudio = null;

    public string $newAudioLabel = 'Vocal';

    public ?int $newAudioDuration = null;

    public function mount(?string $hymn = null): void
    {
        $this->authorizeAbility('content.view');

        if ($hymn === null) {
            $this->number = (int) Hymn::query()->max('number') + 1;
            $this->stanzas = [['type' => 'verse', 'number' => 1, 'lines' => '']];

            return;
        }

        $model = Hymn::query()->with(['stanzas', 'solfa', 'categories'])->findOrFail($hymn);
        $this->hymnId = $model->id;
        $this->fill($model->only(['number', 'title', 'first_line']));
        foreach (['author', 'composer', 'tune', 'meter', 'key', 'tempo', 'about'] as $field) {
            $this->{$field} = (string) $model->{$field};
        }
        $this->scripture_refs = implode('; ', $model->scripture_refs ?? []);
        $this->tags = implode(', ', $model->tags ?? []);
        $this->categoryIds = $model->categories->pluck('id')->all();
        $this->published = $model->published_at !== null;
        $this->stanzas = $model->stanzas->map(fn ($s) => ['type' => $s->type, 'number' => $s->number, 'lines' => implode("\n", $s->lines ?? [])])->all();

        if ($model->solfa) {
            $this->solfaFormat = $model->solfa->format;
            $this->solfaKey = (string) $model->solfa->key;
            $this->solfaTime = (string) $model->solfa->time_signature;
            $this->solfaImagePath = $model->solfa->image_path;
            $this->solfaSections = collect($model->solfa->sections ?? [])->map(fn ($s) => ['label' => $s['label'] ?? '', 'lines' => implode("\n", $s['lines'] ?? [])])->all();
        }
    }

    public function addStanza(string $type = 'verse'): void
    {
        $verses = collect($this->stanzas)->where('type', 'verse')->count();
        $this->stanzas[] = ['type' => $type, 'number' => $type === 'verse' ? $verses + 1 : null, 'lines' => ''];
    }

    public function removeStanza(int $index): void
    {
        unset($this->stanzas[$index]);
        $this->stanzas = array_values($this->stanzas);
    }

    public function moveStanza(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->stanzas[$index], $this->stanzas[$target])) {
            return;
        }
        [$this->stanzas[$index], $this->stanzas[$target]] = [$this->stanzas[$target], $this->stanzas[$index]];
    }

    public function addSolfaSection(): void
    {
        $this->solfaSections[] = ['label' => count($this->solfaSections) === 0 ? 'Soprano' : '', 'lines' => ''];
    }

    public function removeSolfaSection(int $index): void
    {
        unset($this->solfaSections[$index]);
        $this->solfaSections = array_values($this->solfaSections);
    }

    protected function rules(): array
    {
        return [
            'number' => ['required', 'integer', 'min:1', 'max:99999', Rule::unique('hymns', 'number')->ignore($this->hymnId)],
            'title' => ['required', 'string', 'max:200'],
            'first_line' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:200'],
            'composer' => ['nullable', 'string', 'max:200'],
            'tune' => ['nullable', 'string', 'max:120'],
            'meter' => ['nullable', 'string', 'max:60'],
            'key' => ['nullable', 'string', 'max:30'],
            'tempo' => ['nullable', 'string', 'max:60'],
            'about' => ['nullable', 'string', 'max:10000'],
            'scripture_refs' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'categoryIds' => ['array'],
            'categoryIds.*' => ['string', 'exists:hymn_categories,id'],
            'stanzas' => ['required', 'array', 'min:1', 'max:40'],
            'stanzas.*.type' => ['required', Rule::in(['verse', 'chorus', 'refrain', 'bridge'])],
            'stanzas.*.number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'stanzas.*.lines' => ['required', 'string', 'max:4000'],
            'solfaFormat' => ['required', Rule::in(['none', 'text', 'image'])],
            'solfaKey' => ['nullable', 'string', 'max:30'],
            'solfaTime' => ['nullable', 'string', 'max:10'],
            'solfaSections' => ['exclude_unless:solfaFormat,text', 'array', 'min:1'],
            'solfaSections.*.label' => ['exclude_unless:solfaFormat,text', 'required', 'string', 'max:40'],
            'solfaSections.*.lines' => ['exclude_unless:solfaFormat,text', 'required', 'string', 'max:10000'],
            'solfaImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    protected $validationAttributes = [
        'stanzas.*.lines' => 'stanza lines',
        'solfaSections.*.label' => 'voice part',
        'solfaSections.*.lines' => 'solfa lines',
        'categoryIds' => 'categories',
    ];

    public function save(): void
    {
        $this->authorizeAbility('content.manage');
        $data = $this->validate();

        if ($this->solfaFormat === 'image' && ! $this->solfaImage && ! $this->solfaImagePath) {
            $this->addError('solfaImage', 'Upload a scanned solfa sheet.');

            return;
        }

        $split = fn (string $value, string $sep) => array_values(array_filter(array_map('trim', explode($sep, $value)), fn ($v) => $v !== ''));

        $hymn = DB::transaction(function () use ($data, $split) {
            $hymn = $this->hymnId ? Hymn::query()->lockForUpdate()->findOrFail($this->hymnId) : new Hymn;
            $hymn->fill([
                'number' => $data['number'],
                'title' => trim($data['title']),
                'first_line' => trim($data['first_line']),
                'author' => $data['author'] ?: null,
                'composer' => $data['composer'] ?: null,
                'tune' => $data['tune'] ?: null,
                'meter' => $data['meter'] ?: null,
                'key' => $data['key'] ?: null,
                'tempo' => $data['tempo'] ?: null,
                'about' => $data['about'] ?: null,
                'scripture_refs' => $split($data['scripture_refs'] ?? '', ';'),
                'tags' => $split($data['tags'] ?? '', ','),
                'published_at' => $this->published ? ($hymn->published_at ?? now()) : null,
            ]);
            $isNew = ! $hymn->exists;
            $changes = $hymn->isDirty() ? array_keys($hymn->getDirty()) : [];
            $hymn->save();
            $hymn->categories()->sync($data['categoryIds']);

            $hymn->stanzas()->delete();
            foreach (array_values($data['stanzas']) as $i => $s) {
                $hymn->stanzas()->create([
                    'position' => $i + 1,
                    'type' => $s['type'],
                    'number' => $s['type'] === 'verse' ? ($s['number'] ?: null) : null,
                    'lines' => $split($s['lines'], "\n"),
                ]);
            }

            $this->saveSolfa($hymn, $split);
            $this->audit($isNew ? 'hymn.created' : 'hymn.updated', $hymn, "#{$hymn->number} {$hymn->title}", ['fields' => $changes]);

            return $hymn;
        });

        ContentPublished::dispatch('hymns');
        $this->toast('Hymn saved.');

        if (! $this->hymnId) {
            $this->redirectRoute('admin.hymns.edit', ['hymn' => $hymn->id], navigate: true);
        }
    }

    private function saveSolfa(Hymn $hymn, callable $split): void
    {
        $existing = HymnSolfa::query()->find($hymn->id);
        $disk = Storage::disk(config('uec.media.private_disk'));

        if ($this->solfaFormat === 'none') {
            if ($existing?->image_path) {
                $disk->delete($existing->image_path);
            }
            $existing?->delete();
            $this->solfaImagePath = null;

            return;
        }

        $attributes = ['format' => $this->solfaFormat, 'key' => $this->solfaKey ?: null, 'time_signature' => $this->solfaTime ?: null];

        if ($this->solfaFormat === 'text') {
            $attributes['sections'] = array_map(fn ($s) => ['label' => trim($s['label']), 'lines' => $split($s['lines'], "\n")], $this->solfaSections);
            $attributes['image_path'] = null;
        } else {
            $attributes['sections'] = null;
            if ($this->solfaImage) {
                $path = 'hymns/solfa/'.$hymn->id.'-'.Str::random(8).'.'.$this->solfaImage->getClientOriginalExtension();
                $disk->put($path, (string) file_get_contents($this->solfaImage->getRealPath()));
                [$w, $h] = @getimagesize($this->solfaImage->getRealPath()) ?: [null, null];
                if ($existing?->image_path) {
                    $disk->delete($existing->image_path);
                }
                $attributes += ['image_path' => $path, 'width' => $w, 'height' => $h];
                $this->solfaImagePath = $path;
                $this->solfaImage = null;
            }
        }

        HymnSolfa::query()->updateOrCreate(['hymn_id' => $hymn->id], $attributes);
    }

    public function uploadAudio(): void
    {
        $this->authorizeAbility('content.manage');
        abort_unless($this->hymnId, 400);
        $this->validate([
            'newAudio' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg', 'max:51200'],
            'newAudioLabel' => ['required', 'string', 'max:60'],
            'newAudioDuration' => ['nullable', 'integer', 'min:0', 'max:7200'],
        ]);

        $hymn = Hymn::query()->findOrFail($this->hymnId);
        $ext = $this->newAudio->guessExtension() ?: 'mp3';
        $path = 'hymns/audio/'.$hymn->number.'-'.Str::slug($this->newAudioLabel).'-'.Str::random(6).'.'.$ext;
        Storage::disk(config('uec.media.private_disk'))->put($path, (string) file_get_contents($this->newAudio->getRealPath()));

        $audio = $hymn->audio()->create([
            'label' => $this->newAudioLabel,
            'storage_path' => $path,
            'duration_seconds' => (int) $this->newAudioDuration,
            'size_bytes' => (int) $this->newAudio->getSize(),
            'position' => (int) $hymn->audio()->max('position') + 1,
        ]);
        $hymn->touch();
        ContentPublished::dispatch('hymns');
        $this->audit('hymn.audio_added', $hymn, $audio->label);

        $this->reset('newAudio', 'newAudioDuration');
        $this->newAudioLabel = 'Vocal';
        $this->toast('Audio track uploaded.');
    }

    public function deleteAudio(string $audioId): void
    {
        $this->authorizeAbility('content.manage');
        $audio = HymnAudio::query()->where('hymn_id', $this->hymnId)->findOrFail($audioId);
        Storage::disk(config('uec.media.private_disk'))->delete($audio->storage_path);
        $audio->delete();
        Hymn::query()->whereKey($this->hymnId)->first()?->touch();
        ContentPublished::dispatch('hymns');
        $this->audit('hymn.audio_removed', $audio, $audio->label);
        $this->toast('Audio track removed.');
    }

    public function render(MediaUrls $urls)
    {
        $audio = $this->hymnId ? HymnAudio::query()->where('hymn_id', $this->hymnId)->orderBy('position')->get() : collect();

        return view('livewire.admin.content.hymns.form', [
            'categories' => HymnCategory::query()->orderBy('sort')->get(),
            'audio' => $audio,
            'audioUrl' => fn (HymnAudio $a) => $urls->signedPrivateUrl($a->storage_path),
            'solfaPreview' => $this->solfaImagePath ? $urls->signedPrivateUrl($this->solfaImagePath) : null,
        ])->title($this->hymnId ? "Edit hymn {$this->number}" : 'New hymn');
    }
}
