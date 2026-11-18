<div>
    <x-admin.page-header :title="$entryId ? 'Edit reading' : 'New reading'" :description="'Daily Delight '.$year" :back="route('admin.devotionals.entries', $year)">
        <x-slot:actions>
            @can('content.manage')
                <button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled"><x-admin.icon name="check" class="size-4" /> Save reading</button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <fieldset @disabled(! auth()->user()->can('content.manage')) class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card space-y-4 p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-admin.field label="Date" for="date" error="date" required><input id="date" type="date" min="{{ $year }}-01-01" max="{{ $year }}-12-31" wire:model="date" class="input"></x-admin.field>
                    <x-admin.field label="Title" for="title" error="title" required class="sm:col-span-2"><input id="title" type="text" wire:model="title" class="input"></x-admin.field>
                </div>
                <x-admin.field label="Main passage" for="passage" error="passage" required help="e.g. Joshua 1:1-9 — tappable in the app"><input id="passage" type="text" wire:model="passage" class="input"></x-admin.field>
            </section>
            <section class="card p-5">
                <x-admin.rich-text model="body" label="Message" :rows="16" required />
            </section>
        </div>
        <div class="space-y-6">
            <section class="card space-y-4 p-5">
                <h2 class="card-title">Memory verse</h2>
                <x-admin.field label="Reference" for="mvr" error="memory_verse_ref"><input id="mvr" type="text" wire:model="memory_verse_ref" class="input" placeholder="Psalm 119:11"></x-admin.field>
                <x-admin.field label="Text" for="mvt" error="memory_verse_text"><textarea id="mvt" wire:model="memory_verse_text" rows="4" class="input"></textarea></x-admin.field>
            </section>
            <section class="card space-y-4 p-5">
                <x-admin.rich-text model="declaration" label="Declaration" :rows="4" help="Shown after the message." />
                <x-admin.rich-text model="prayer" label="Prayer" :rows="4" help="Closing prayer." />
            </section>
        </div>
    </fieldset>
</div>
