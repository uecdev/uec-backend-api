<div>
    <x-admin.page-header :title="$lessonId ? 'Lesson '.$number.': '.$title : 'New lesson'" :description="$year.' Sunday School Manual'" :back="route('admin.sunday-school.manual', $year)">
        <x-slot:actions>
            @can('content.manage')<button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled"><x-admin.icon name="check" class="size-4" /> Save lesson</button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    @if ($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><strong>Please fix the highlighted fields.</strong> {{ $errors->first() }}</div>
    @endif

    <fieldset @disabled(! auth()->user()->can('content.manage')) class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card space-y-4 p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <x-admin.field label="No." for="number" error="number" required class="sm:col-span-1"><input id="number" type="number" min="1" wire:model="number" class="input"></x-admin.field>
                    <x-admin.field label="Title" for="title" error="title" required class="sm:col-span-5"><input id="title" type="text" wire:model="title" class="input"></x-admin.field>
                </div>
                <x-admin.field label="Passage" for="passage" error="passage" required><input id="passage" type="text" wire:model="passage" class="input" placeholder="Matthew 2:1-12"></x-admin.field>
                <x-admin.rich-text model="aim" label="Aim" :rows="3" />
                <x-admin.rich-text model="introduction" label="Introduction" :rows="6" />
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Outline</h2><button type="button" class="btn-secondary btn-sm" wire:click="addItem('outline')"><x-admin.icon name="plus" class="size-3.5" /> Add point</button></div>
                <div class="divide-y divide-line">
                    @foreach ($outline as $i => $point)
                        <div class="space-y-3 p-5" wire:key="o-{{ $i }}">
                            <div class="flex gap-2">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 font-black text-brand-700">{{ $i + 1 }}</span>
                                <input type="text" wire:model="outline.{{ $i }}.heading" class="input" placeholder="Heading" aria-label="Outline heading {{ $i + 1 }}">
                                <button type="button" class="btn-ghost text-red-700" wire:click="removeItem('outline', {{ $i }})" aria-label="Remove point"><x-admin.icon name="trash" class="size-4" /></button>
                            </div>
                            @error("outline.$i.heading")<p class="error">{{ $message }}</p>@enderror
                            <x-admin.rich-text :model="'outline.'.$i.'.body'" label="Body" :rows="5" />
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card p-5">
                <x-admin.rich-text model="conclusion" label="Conclusion" :rows="4" />
            </section>
        </div>

        <div class="space-y-6">
            <section class="card space-y-4 p-5">
                <h2 class="card-title">Memory verse</h2>
                <x-admin.field label="Reference" for="mvr" error="memory_verse_ref"><input id="mvr" type="text" wire:model="memory_verse_ref" class="input" placeholder="Psalm 119:11"></x-admin.field>
                <x-admin.field label="Text" for="mvt" error="memory_verse_text"><textarea id="mvt" wire:model="memory_verse_text" rows="4" class="input"></textarea></x-admin.field>
            </section>

            @foreach (['applications' => ['Applications', 'Application'], 'revision_questions' => ['Revision questions', 'Question']] as $list => [$heading, $singular])
                <section class="card">
                    <div class="card-header"><h2 class="card-title">{{ $heading }}</h2><button type="button" class="btn-secondary btn-sm" wire:click="addItem('{{ $list }}')"><x-admin.icon name="plus" class="size-3.5" /> Add</button></div>
                    <div class="space-y-3 p-5">
                        @foreach ($this->{$list} as $i => $item)
                            <div class="flex gap-2" wire:key="{{ $list }}-{{ $i }}">
                                <textarea wire:model.blur="{{ $list }}.{{ $i }}" rows="2" class="input" aria-label="{{ $singular }} {{ $i + 1 }}"></textarea>
                                <button type="button" class="btn-ghost self-start text-red-700" wire:click="removeItem('{{ $list }}', {{ $i }})" aria-label="Remove"><x-admin.icon name="trash" class="size-4" /></button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </fieldset>
</div>
