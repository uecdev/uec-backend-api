@php $canEdit = auth()->user()->can('content.manage'); @endphp
<div>
    <x-admin.page-header :title="$hymnId ? 'Hymn '.$number.': '.$title : 'New hymn'" :back="route('admin.hymns.index')">
        <x-slot:actions>
            @if ($canEdit)
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-white px-3 py-2 text-sm font-bold">
                    <input type="checkbox" wire:model="published" class="size-4 rounded border-line text-brand-700"> Published
                </label>
                <button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <x-admin.icon name="check" class="size-4" /> <span wire:loading.remove wire:target="save">Save hymn</span><span wire:loading wire:target="save">Saving…</span>
                </button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <strong>Please fix {{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }}.</strong> {{ $errors->first() }}
        </div>
    @endif

    <fieldset @disabled(! $canEdit) class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card p-5">
                <h2 class="card-title mb-4">Details</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <x-admin.field label="Number" for="number" error="number" required class="sm:col-span-1">
                        <input id="number" type="number" min="1" wire:model="number" class="input">
                    </x-admin.field>
                    <x-admin.field label="Title" for="title" error="title" required class="sm:col-span-5">
                        <input id="title" type="text" wire:model="title" class="input">
                    </x-admin.field>
                    <x-admin.field label="First line" for="first_line" error="first_line" required class="sm:col-span-6" help="Shown in the catalogue and used by search.">
                        <input id="first_line" type="text" wire:model="first_line" class="input">
                    </x-admin.field>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Lyrics</h2>
                        <p class="text-xs text-muted">One line per row. Stanzas appear in this order.</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary btn-sm" wire:click="addStanza('verse')"><x-admin.icon name="plus" class="size-3.5" /> Verse</button>
                        <button type="button" class="btn-secondary btn-sm" wire:click="addStanza('chorus')"><x-admin.icon name="plus" class="size-3.5" /> Chorus</button>
                    </div>
                </div>
                <div class="divide-y divide-line">
                    @foreach ($stanzas as $i => $stanza)
                        <div class="p-5" wire:key="stanza-{{ $i }}">
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                <select wire:model.live="stanzas.{{ $i }}.type" class="input w-36" aria-label="Stanza type">
                                    <option value="verse">Verse</option><option value="chorus">Chorus</option><option value="refrain">Refrain</option><option value="bridge">Bridge</option>
                                </select>
                                @if (($stanza['type'] ?? '') === 'verse')
                                    <input type="number" min="1" wire:model="stanzas.{{ $i }}.number" class="input w-20" aria-label="Verse number">
                                @endif
                                <div class="ml-auto flex gap-1">
                                    <button type="button" class="btn-ghost btn-sm" wire:click="moveStanza({{ $i }}, -1)" @disabled($i === 0) aria-label="Move up"><x-admin.icon name="chevron-up" class="size-4" /></button>
                                    <button type="button" class="btn-ghost btn-sm" wire:click="moveStanza({{ $i }}, 1)" @disabled($i === count($stanzas) - 1) aria-label="Move down"><x-admin.icon name="chevron-down" class="size-4" /></button>
                                    <button type="button" class="btn-ghost btn-sm text-red-700" wire:click="removeStanza({{ $i }})" aria-label="Remove stanza"><x-admin.icon name="trash" class="size-4" /></button>
                                </div>
                            </div>
                            <textarea wire:model.blur="stanzas.{{ $i }}.lines" rows="4" class="input leading-relaxed" aria-label="Stanza lines"></textarea>
                            @error("stanzas.$i.lines")<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Solfa notation <span class="badge ml-1 bg-gold-50 text-gold-700">Premium</span></h2>
                        <p class="text-xs text-muted">Text per voice part (monospace in the app) or a scanned sheet.</p>
                    </div>
                    <div class="flex rounded-xl border border-line bg-cream p-1">
                        @foreach (['none' => 'None', 'text' => 'Text', 'image' => 'Image'] as $value => $label)
                            <button type="button" wire:click="$set('solfaFormat', '{{ $value }}')" @class(['tab', 'tab-active' => $solfaFormat === $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                @if ($solfaFormat !== 'none')
                    <div class="space-y-4 p-5">
                        <div class="grid grid-cols-2 gap-4">
                            <x-admin.field label="Key" for="solfaKey" error="solfaKey"><input id="solfaKey" type="text" wire:model="solfaKey" class="input" placeholder="E♭"></x-admin.field>
                            <x-admin.field label="Time signature" for="solfaTime" error="solfaTime"><input id="solfaTime" type="text" wire:model="solfaTime" class="input" placeholder="4/4"></x-admin.field>
                        </div>
                        @if ($solfaFormat === 'text')
                            @foreach ($solfaSections as $i => $section)
                                <div class="rounded-xl border border-line p-4" wire:key="solfa-{{ $i }}">
                                    <div class="mb-2 flex gap-2">
                                        <input type="text" wire:model="solfaSections.{{ $i }}.label" class="input" placeholder="Voice part, e.g. Soprano" aria-label="Voice part">
                                        <button type="button" class="btn-ghost text-red-700" wire:click="removeSolfaSection({{ $i }})" aria-label="Remove part"><x-admin.icon name="trash" class="size-4" /></button>
                                    </div>
                                    <textarea wire:model.blur="solfaSections.{{ $i }}.lines" rows="4" class="input font-mono text-xs" placeholder="d : d | m : s | …" aria-label="Solfa lines"></textarea>
                                    @error("solfaSections.$i.lines")<p class="error">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                            <button type="button" class="btn-secondary btn-sm" wire:click="addSolfaSection"><x-admin.icon name="plus" class="size-3.5" /> Add voice part</button>
                            @error('solfaSections')<p class="error">Add at least one voice part.</p>@enderror
                        @else
                            <x-admin.field label="Scanned sheet (JPG, PNG or WebP, max 8 MB)" for="solfaImage" error="solfaImage">
                                <input id="solfaImage" type="file" wire:model="solfaImage" accept="image/jpeg,image/png,image/webp" class="input">
                            </x-admin.field>
                            <div wire:loading wire:target="solfaImage" class="text-sm text-muted">Uploading…</div>
                            @if ($solfaImage)
                                <img src="{{ $solfaImage->temporaryUrl() }}" alt="Solfa preview" class="max-h-80 rounded-xl border border-line">
                            @elseif ($solfaPreview)
                                <img src="{{ $solfaPreview }}" alt="Current solfa sheet" class="max-h-80 rounded-xl border border-line">
                            @endif
                        @endif
                    </div>
                @endif
            </section>

            <section class="card p-5">
                <x-admin.rich-text model="about" label="About this hymn" :rows="5" />
            </section>
        </div>

        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="card-title mb-3">Categories</h2>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($categories as $c)
                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-cream">
                            <input type="checkbox" value="{{ $c->id }}" wire:model="categoryIds" class="size-4 rounded border-line text-brand-700"> {{ $c->name }}
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="card space-y-4 p-5">
                <h2 class="card-title">Music details</h2>
                <x-admin.field label="Author" for="author" error="author"><input id="author" type="text" wire:model="author" class="input" placeholder="Horatius Bonar (1808–1889)"></x-admin.field>
                <x-admin.field label="Composer" for="composer" error="composer"><input id="composer" type="text" wire:model="composer" class="input"></x-admin.field>
                <div class="grid grid-cols-2 gap-3">
                    <x-admin.field label="Tune" for="tune" error="tune"><input id="tune" type="text" wire:model="tune" class="input"></x-admin.field>
                    <x-admin.field label="Meter" for="meter" error="meter"><input id="meter" type="text" wire:model="meter" class="input" placeholder="8.7.8.7"></x-admin.field>
                    <x-admin.field label="Key" for="key" error="key"><input id="key" type="text" wire:model="key" class="input" placeholder="E♭ Major"></x-admin.field>
                    <x-admin.field label="Tempo" for="tempo" error="tempo"><input id="tempo" type="text" wire:model="tempo" class="input" placeholder="Moderato"></x-admin.field>
                </div>
                <x-admin.field label="Scripture references" for="refs" error="scripture_refs" help="Separate with semicolons, e.g. Revelation 5:13; Psalm 150">
                    <input id="refs" type="text" wire:model="scripture_refs" class="input">
                </x-admin.field>
                <x-admin.field label="Tags" for="tags" error="tags" help="Comma separated">
                    <input id="tags" type="text" wire:model="tags" class="input" placeholder="Praise, Trinity">
                </x-admin.field>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Audio <span class="badge ml-1 bg-gold-50 text-gold-700">Premium</span></h2>
                </div>
                @if (! $hymnId)
                    <p class="p-5 text-sm text-muted">Save the hymn first, then upload audio tracks.</p>
                @else
                    <ul class="divide-y divide-line">
                        @forelse ($audio as $track)
                            <li class="space-y-2 px-5 py-3" wire:key="audio-{{ $track->id }}">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-bold">{{ $track->label }}</span>
                                    <span class="text-xs text-muted">{{ gmdate('i:s', $track->duration_seconds) }} · {{ number_format($track->size_bytes / 1048576, 1) }} MB</span>
                                </div>
                                <audio controls preload="none" class="h-9 w-full" src="{{ $audioUrl($track) }}"></audio>
                                @if ($canEdit)
                                    <button type="button" class="text-xs font-bold text-red-700 hover:underline"
                                            @click="$store.confirm.ask({ title: 'Remove this audio track?', message: 'The file is deleted from storage.', confirmLabel: 'Remove', onConfirm: () => $wire.deleteAudio('{{ $track->id }}') })">Remove</button>
                                @endif
                            </li>
                        @empty
                            <li class="px-5 py-4 text-sm text-muted">No audio tracks yet.</li>
                        @endforelse
                    </ul>
                    @if ($canEdit)
                        <div class="space-y-3 border-t border-line p-5" x-data="{ readDuration(e) { const f = e.target.files[0]; if (!f) return; const a = new Audio(URL.createObjectURL(f)); a.addEventListener('loadedmetadata', () => $wire.set('newAudioDuration', Math.round(a.duration))); } }">
                            <x-admin.field label="Label" for="audioLabel" error="newAudioLabel"><input id="audioLabel" type="text" wire:model="newAudioLabel" class="input" placeholder="Vocal, Instrumental…"></x-admin.field>
                            <x-admin.field label="File (MP3, M4A, AAC or OGG, max 50 MB)" for="newAudio" error="newAudio">
                                <input id="newAudio" type="file" wire:model="newAudio" accept="audio/*" class="input" @change="readDuration($event)">
                            </x-admin.field>
                            <p class="text-xs text-muted" wire:loading wire:target="newAudio">Uploading…</p>
                            @if ($newAudioDuration)<p class="text-xs text-muted">Duration detected: {{ gmdate('i:s', $newAudioDuration) }}</p>@endif
                            <button type="button" class="btn-secondary w-full" wire:click="uploadAudio" wire:loading.attr="disabled" wire:target="uploadAudio,newAudio"><x-admin.icon name="upload" class="size-4" /> Add track</button>
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </fieldset>
</div>
