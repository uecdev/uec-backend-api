<div>
    <x-admin.page-header title="Daily Delight" description="Yearly devotional editions. The current-year edition is free; editions marked premium require a subscription (archive access).">
        <x-slot:actions>
            @can('content.manage')<button type="button" wire:click="create" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New edition</button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($editions as $e)
            @php $days = \Illuminate\Support\Carbon::create($e->year)->isLeapYear() ? 366 : 365; $pct = min(100, round($e->entries_count / $days * 100)); @endphp
            <article class="card flex flex-col" wire:key="ed-{{ $e->year }}">
                <div class="flex-1 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-3xl font-black text-brand-700">{{ $e->year }}</div>
                            <h2 class="mt-1 font-black">{{ $e->title }}</h2>
                            <p class="text-sm text-muted">{{ $e->theme ?: 'No theme set' }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if ($e->isPublished())<x-admin.status value="published" />@else<x-admin.status value="draft" />@endif
                            @if ($e->is_premium)<span class="badge bg-gold-50 text-gold-700">Premium</span>@endif
                        </div>
                    </div>
                    <div class="mt-5">
                        <div class="flex justify-between text-xs text-muted"><span>{{ number_format($e->entries_count) }} of {{ $days }} days written</span><span>{{ $pct }}%</span></div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-cream"><div class="h-full rounded-full bg-brand-600" style="width: {{ $pct }}%"></div></div>
                    </div>
                    <p class="mt-3 text-xs text-muted">Offline bundle: {{ $e->bundle_size ? number_format($e->bundle_size / 1048576, 2).' MB' : 'not built yet' }}</p>
                </div>
                <div class="flex gap-2 border-t border-line p-3">
                    <a href="{{ route('admin.devotionals.entries', $e->year) }}" wire:navigate class="btn-secondary flex-1">Entries</a>
                    @can('content.manage')<button type="button" wire:click="edit({{ $e->year }})" class="btn-ghost" aria-label="Edit edition"><x-admin.icon name="edit" class="size-4" /></button>@endcan
                </div>
            </article>
        @empty
            <div class="card md:col-span-2 xl:col-span-3"><x-admin.empty title="No editions yet" icon="book">Create the first yearly edition, then add one entry per day.</x-admin.empty></div>
        @endforelse
    </div>

    <x-admin.modal show="showForm" :title="$editingYear ? 'Edit edition' : 'New edition'" size="max-w-lg">
        <div class="space-y-4">
            <x-admin.field label="Year" for="year" error="year" required><input id="year" type="number" wire:model="year" class="input"></x-admin.field>
            <x-admin.field label="Title" for="etitle" error="title" required><input id="etitle" type="text" wire:model="title" class="input"></x-admin.field>
            <x-admin.field label="Theme" for="theme" error="theme" help="e.g. Exceeding Great Joy"><input id="theme" type="text" wire:model="theme" class="input"></x-admin.field>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="is_premium" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Premium (archive)</strong><br><span class="text-muted">Only subscribers can read this edition. Leave off for the current year.</span></span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="published" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Published</strong><br><span class="text-muted">Visible in the app. Entries dated after today stay hidden until their day.</span></span></label>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="save">Save edition</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
