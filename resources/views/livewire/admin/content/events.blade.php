<div>
    <x-admin.page-header title="National Calendar of Events" description="Events appear in the app’s calendar for the year they start. Times are Africa/Lagos.">
        <x-slot:actions>@can('content.manage')<button type="button" wire:click="create" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New event</button>@endcan</x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
            <x-admin.search placeholder="Search events" />
            <select wire:model.live="filterYear" class="input sm:ml-auto sm:w-40" aria-label="Year">
                @foreach (range(now()->year + 1, now()->year - 4) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead><tr>
                    <x-admin.sort-header field="start_date" label="Date" :sort-field="$sortField ?: 'start_date'" :sort-direction="$sortField ? $sortDirection : 'asc'" />
                    <x-admin.sort-header field="title" label="Event" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th>Location</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th>
                </tr></thead>
                <tbody>
                @forelse ($events as $e)
                    <tr wire:key="e-{{ $e->id }}">
                        <td class="whitespace-nowrap">
                            <span class="font-bold">{{ $e->start_date->format('j M Y') }}</span>
                            @if ($e->end_date && ! $e->end_date->equalTo($e->start_date))<span class="text-muted">– {{ $e->end_date->format('j M') }}</span>@endif
                            <span class="block text-xs text-muted">{{ $e->start_time ?: 'All day' }}</span>
                        </td>
                        <td class="max-w-md"><span class="block truncate font-bold">{{ $e->title }}</span>@if ($e->category)<span class="badge mt-0.5 bg-cream text-muted">{{ $e->category }}</span>@endif</td>
                        <td class="text-muted">{{ $e->location ?: '—' }}</td>
                        <td>@if ($e->isPublished())<x-admin.status value="published" />@else<x-admin.status value="draft" />@endif</td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <button type="button" class="btn-ghost btn-sm" wire:click="edit('{{ $e->id }}')" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Delete"
                                        @click="$store.confirm.ask({ title: 'Delete this event?', message: @js($e->title), confirmLabel: 'Delete', onConfirm: () => $wire.delete('{{ $e->id }}') })"><x-admin.icon name="trash" class="size-4" /></button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No events for this year" icon="calendar" colspan="5" />
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($events->hasPages())<div class="border-t border-line px-4 py-3">{{ $events->links() }}</div>@endif
    </div>

    <x-admin.modal show="showForm" :title="$editingId ? 'Edit event' : 'New event'">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-admin.field label="Title" for="etitle" error="title" required class="sm:col-span-2"><input id="etitle" type="text" wire:model="title" class="input" placeholder="National Youth Convention 2026"></x-admin.field>
            <x-admin.field label="Start date" for="sd" error="start_date" required><input id="sd" type="date" wire:model="start_date" class="input"></x-admin.field>
            <x-admin.field label="End date" for="ed" error="end_date"><input id="ed" type="date" wire:model="end_date" class="input"></x-admin.field>
            <x-admin.field label="Start time" for="st" error="start_time" help="Leave empty for all-day events"><input id="st" type="time" wire:model="start_time" class="input"></x-admin.field>
            <x-admin.field label="Category" for="cat" error="category" help="e.g. youth, women, convention"><input id="cat" type="text" wire:model="category" class="input"></x-admin.field>
            <x-admin.field label="Location" for="loc" error="location" class="sm:col-span-2"><input id="loc" type="text" wire:model="location" class="input" placeholder="Uyo, Akwa Ibom"></x-admin.field>
            <x-admin.field label="Description" for="desc" error="description" class="sm:col-span-2" help="Plain text"><textarea id="desc" wire:model="description" rows="4" class="input"></textarea></x-admin.field>
            <x-admin.field label="Share link" for="share" error="share_url" class="sm:col-span-2" help="Optional https link used by “Share” in the app"><input id="share" type="url" wire:model="share_url" class="input" placeholder="https://"></x-admin.field>
            <label class="flex items-center gap-2 text-sm font-bold sm:col-span-2"><input type="checkbox" wire:model="published" class="size-4 rounded border-line text-brand-700"> Published</label>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="save">Save event</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
