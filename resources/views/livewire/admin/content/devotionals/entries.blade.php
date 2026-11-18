<div>
    <x-admin.page-header :title="$edition->title" :description="$edition->theme" :back="route('admin.devotionals.index')">
        <x-slot:actions>
            @can('content.manage')
                <button type="button" wire:click="$set('showImport', true)" class="btn-secondary"><x-admin.icon name="upload" class="size-4" /> Import CSV</button>
                <a href="{{ route('admin.devotionals.entry.create', $year) }}" wire:navigate class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New entry</a>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-5 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Month">
        @foreach (range(1, 12) as $m)
            @php $total = \Illuminate\Support\Carbon::create($year, $m)->daysInMonth; $count = $monthCounts[$m] ?? 0; @endphp
            <button type="button" role="tab" aria-selected="{{ $month === $m ? 'true' : 'false' }}" wire:click="$set('month', {{ $m }})"
                    @class(['shrink-0 rounded-xl border px-3 py-2 text-left text-sm transition', 'border-brand-700 bg-brand-700 text-white' => $month === $m, 'border-line bg-white hover:border-brand-300' => $month !== $m])>
                <span class="block font-black">{{ \Illuminate\Support\Carbon::create($year, $m)->format('M') }}</span>
                <span @class(['block text-xs', 'text-brand-100' => $month === $m, 'text-muted' => $month !== $m])>{{ $count }}/{{ $total }}</span>
            </button>
        @endforeach
    </div>

    <div class="card overflow-x-auto" wire:loading.class="opacity-60">
        <table class="table">
            <thead><tr><th>Date</th><th>Title</th><th>Passage</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            @foreach ($days as $date)
                @php $entry = $entries[$date] ?? null; $d = \Illuminate\Support\Carbon::parse($date); @endphp
                <tr wire:key="d-{{ $date }}">
                    <td class="whitespace-nowrap"><span class="font-black">{{ $d->format('j M') }}</span> <span class="text-xs text-muted">{{ $d->format('D') }}</span></td>
                    @if ($entry)
                        <td class="max-w-md"><a href="{{ route('admin.devotionals.entry.edit', [$year, $entry->id]) }}" wire:navigate class="block truncate font-bold hover:text-brand-700">{{ $entry->title }}</a></td>
                        <td class="whitespace-nowrap text-muted">{{ $entry->passage }}</td>
                        <td>
                            @if (! $edition->isPublished())<x-admin.status value="draft" />
                            @elseif ($date > $today)<span class="badge bg-sky-50 text-sky-800">Scheduled</span>
                            @else<x-admin.status value="published" />@endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <a href="{{ route('admin.devotionals.entry.edit', [$year, $entry->id]) }}" wire:navigate class="btn-ghost btn-sm" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></a>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Delete"
                                        @click="$store.confirm.ask({ title: 'Delete the {{ $d->format('j F') }} reading?', message: 'The app will show “No reading for this day”.', confirmLabel: 'Delete', onConfirm: () => $wire.delete('{{ $entry->id }}') })"><x-admin.icon name="trash" class="size-4" /></button>
                            @endcan
                        </td>
                    @else
                        <td colspan="3" class="text-sm text-muted italic">No reading written</td>
                        <td class="text-right">
                            @can('content.manage')<a href="{{ route('admin.devotionals.entry.create', [$year, 'date' => $date]) }}" wire:navigate class="btn-ghost btn-sm"><x-admin.icon name="plus" class="size-4" /> Write</a>@endcan
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <x-admin.modal show="showImport" title="Import entries from CSV" size="max-w-xl">
        <div class="space-y-4 text-sm">
            <p class="text-muted">UTF-8 CSV with a header row. Required columns: <code class="rounded bg-cream px-1">date</code> (YYYY-MM-DD in {{ $year }}), <code class="rounded bg-cream px-1">title</code>, <code class="rounded bg-cream px-1">passage</code>, <code class="rounded bg-cream px-1">body</code>. Optional: <code class="rounded bg-cream px-1">memory_verse_text</code>, <code class="rounded bg-cream px-1">memory_verse_ref</code>, <code class="rounded bg-cream px-1">declaration</code>, <code class="rounded bg-cream px-1">prayer</code>. Existing dates are updated. Nothing is saved if any row is invalid.</p>
            <x-admin.field label="CSV file" for="importFile" error="importFile"><input id="importFile" type="file" wire:model="importFile" accept=".csv,text/csv" class="input"></x-admin.field>
            @if ($importErrors)
                <div class="max-h-48 overflow-y-auto rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-800" role="alert">
                    @foreach ($importErrors as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="import" wire:loading.attr="disabled" wire:target="import,importFile">Import</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
