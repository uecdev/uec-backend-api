<div>
    <x-admin.page-header title="Reports" description="Operational and executive reports from live data. Dates use Africa/Lagos time.">
        <x-slot:actions>
            @can('reports.export')<button type="button" wire:click="export" class="btn-primary"><x-admin.icon name="download" class="size-4" /> Download CSV</button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <nav class="card h-fit p-2" aria-label="Report type">
            @foreach ($reports as $key => $r)
                <button type="button" wire:click="$set('report', '{{ $key }}')" @class(['block w-full rounded-xl px-3 py-2.5 text-left transition', 'bg-brand-50' => $report === $key, 'hover:bg-cream' => $report !== $key])>
                    <span @class(['block text-sm font-bold', 'text-brand-800' => $report === $key])>{{ $r['label'] }}</span>
                    <span class="block text-xs text-muted">{{ $r['description'] }}</span>
                </button>
            @endforeach
        </nav>

        <section class="card lg:col-span-3">
            <div class="card-header">
                <div>
                    <h2 class="card-title">{{ $reports[$report]['label'] }}</h2>
                    <p class="text-xs text-muted">{{ number_format($result['total_rows']) }} rows{{ $result['total_rows'] > count($result['rows']) ? ' · showing the first '.count($result['rows']).'; download for all' : '' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="date" wire:model.live="from" class="input w-40" aria-label="From">
                    <span class="text-muted">to</span>
                    <input type="date" wire:model.live="to" class="input w-40" aria-label="To">
                </div>
            </div>
            @error('to')<p class="error px-5 pt-3">{{ $message }}</p>@enderror
            <div class="overflow-x-auto" wire:loading.class="opacity-60">
                <table class="table">
                    <thead><tr>@foreach ($result['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr>@foreach ($row as $value)<td class="tabular-nums">{{ $value ?? '—' }}</td>@endforeach</tr>
                    @empty
                        <x-admin.empty title="No data in this range" icon="chart" :colspan="count($result['columns'])" />
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
