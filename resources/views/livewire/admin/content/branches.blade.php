<div>
    <x-admin.page-header title="Branch directory" :description="'Searchable in the app with map, directions and call actions.'.($missingCoords ? ' '.$missingCoords.' branches have no coordinates — the app falls back to an address search.' : '')">
        <x-slot:actions>
            @can('content.manage')
                <button type="button" wire:click="$set('showImport', true)" class="btn-secondary"><x-admin.icon name="upload" class="size-4" /> Import CSV</button>
                <button type="button" wire:click="create" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New branch</button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <div class="border-b border-line p-4"><x-admin.search placeholder="Name, address, city or state" /></div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead><tr>
                    <x-admin.sort-header field="name" label="Branch" :sort-field="$sortField ?: 'name'" :sort-direction="$sortField ? $sortDirection : 'asc'" />
                    <x-admin.sort-header field="city" label="City" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <x-admin.sort-header field="state" label="State" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th>Phone</th><th>Map</th><th class="text-right"><span class="sr-only">Actions</span></th>
                </tr></thead>
                <tbody>
                @forelse ($branches as $b)
                    <tr wire:key="b-{{ $b->id }}">
                        <td class="max-w-xs"><span class="block truncate font-bold">{{ $b->name }}</span><span class="block truncate text-xs text-muted">{{ $b->address }}</span></td>
                        <td class="text-muted">{{ $b->city ?: '—' }}</td>
                        <td class="text-muted">{{ $b->state ?: '—' }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $b->phone ?: '—' }}</td>
                        <td>
                            @if ($b->latitude !== null)
                                <a href="https://www.openstreetmap.org/?mlat={{ $b->latitude }}&mlon={{ $b->longitude }}#map=16/{{ $b->latitude }}/{{ $b->longitude }}" target="_blank" rel="noopener" class="text-xs font-bold text-brand-700 hover:underline">View</a>
                            @else<span class="badge bg-gold-50 text-gold-700">No pin</span>@endif
                            @unless ($b->is_active)<span class="badge bg-slate-100 text-slate-700">Hidden</span>@endunless
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <button type="button" class="btn-ghost btn-sm" wire:click="edit('{{ $b->id }}')" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Delete"
                                        @click="$store.confirm.ask({ title: 'Delete branch?', message: @js($b->name), confirmLabel: 'Delete', onConfirm: () => $wire.delete('{{ $b->id }}') })"><x-admin.icon name="trash" class="size-4" /></button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No branches" icon="map" colspan="6" />
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($branches->hasPages())<div class="border-t border-line px-4 py-3">{{ $branches->links() }}</div>@endif
    </div>

    <x-admin.modal show="showForm" :title="$editingId ? 'Edit branch' : 'New branch'">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-admin.field label="Name" for="bname" error="name" required class="sm:col-span-2"><input id="bname" type="text" wire:model="name" class="input"></x-admin.field>
            <x-admin.field label="Address" for="baddr" error="address" required class="sm:col-span-2"><input id="baddr" type="text" wire:model="address" class="input"></x-admin.field>
            <x-admin.field label="City" for="bcity" error="city"><input id="bcity" type="text" wire:model="city" class="input"></x-admin.field>
            <x-admin.field label="State" for="bstate" error="state"><input id="bstate" type="text" wire:model="state" class="input"></x-admin.field>
            <x-admin.field label="Phone" for="bphone" error="phone"><input id="bphone" type="tel" wire:model="phone" class="input"></x-admin.field>
            <div></div>
            <x-admin.field label="Latitude" for="blat" error="latitude"><input id="blat" type="text" inputmode="decimal" wire:model="latitude" class="input" placeholder="4.8156"></x-admin.field>
            <x-admin.field label="Longitude" for="blng" error="longitude"><input id="blng" type="text" inputmode="decimal" wire:model="longitude" class="input" placeholder="7.0498"></x-admin.field>
            <label class="flex items-center gap-2 text-sm font-bold sm:col-span-2"><input type="checkbox" wire:model="is_active" class="size-4 rounded border-line text-brand-700"> Visible in the app</label>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="save">Save branch</button>
        </x-slot:footer>
    </x-admin.modal>

    <x-admin.modal show="showImport" title="Import branches from CSV" size="max-w-xl">
        <div class="space-y-4 text-sm">
            <p class="text-muted">Header row: <code class="rounded bg-cream px-1">name,address,city,state,phone,latitude,longitude</code>. Rows are added as new branches; nothing is saved if any row is invalid.</p>
            <x-admin.field label="CSV file" for="bimport" error="importFile"><input id="bimport" type="file" wire:model="importFile" accept=".csv,text/csv" class="input"></x-admin.field>
            @if ($importErrors)<div class="max-h-48 overflow-y-auto rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-800" role="alert">@foreach ($importErrors as $e)<div>{{ $e }}</div>@endforeach</div>@endif
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="import" wire:loading.attr="disabled" wire:target="import,importFile">Import</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
