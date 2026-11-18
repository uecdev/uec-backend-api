<div>
    <x-admin.page-header title="Hymnal" :description="number_format($totals['published']).' of '.number_format($totals['all']).' hymns are published to the app. Audio and solfa are premium features.'">
        <x-slot:actions>
            @can('content.manage')
                <a href="{{ route('admin.hymns.create') }}" wire:navigate class="btn-primary"><x-admin.icon name="plus" class="size-4" /> New hymn</a>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 lg:flex-row lg:items-center">
            <x-admin.search placeholder="Number, title or first line" />
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:ml-auto">
                <select wire:model.live="filterCategory" class="input" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $c)<option value="{{ $c->slug }}">{{ $c->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterStatus" class="input" aria-label="Status">
                    <option value="">Published & drafts</option>
                    <option value="published">Published</option>
                    <option value="draft">Drafts</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead>
                <tr>
                    <x-admin.sort-header field="number" label="No." :sort-field="$sortField ?: 'number'" :sort-direction="$sortField ? $sortDirection : 'asc'" />
                    <x-admin.sort-header field="title" label="Hymn" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th>Categories</th>
                    <th>Media</th>
                    <th>Status</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($hymns as $hymn)
                    <tr wire:key="h-{{ $hymn->id }}">
                        <td class="font-black tabular-nums text-brand-700">{{ $hymn->number }}</td>
                        <td class="max-w-sm">
                            <a href="{{ route('admin.hymns.edit', $hymn->id) }}" wire:navigate class="block truncate font-bold hover:text-brand-700">{{ $hymn->title }}</a>
                            <span class="block truncate text-xs text-muted">{{ $hymn->first_line }}</span>
                        </td>
                        <td><div class="flex flex-wrap gap-1">@foreach ($hymn->categories as $c)<span class="badge bg-cream text-muted">{{ $c->name }}</span>@endforeach</div></td>
                        <td class="whitespace-nowrap text-xs text-muted">
                            {{ $hymn->stanzas_count }} stanzas
                            @if ($hymn->audio_count) · <span class="font-bold text-gold-700">{{ $hymn->audio_count }} audio</span>@endif
                            @if ($hymn->solfa_exists) · <span class="font-bold text-gold-700">solfa</span>@endif
                        </td>
                        <td>@if ($hymn->isPublished())<x-admin.status value="published" />@else<x-admin.status value="draft" />@endif</td>
                        <td class="text-right whitespace-nowrap">
                            @can('content.manage')
                                <button type="button" class="btn-ghost btn-sm" wire:click="togglePublished('{{ $hymn->id }}')">{{ $hymn->isPublished() ? 'Unpublish' : 'Publish' }}</button>
                                <a href="{{ route('admin.hymns.edit', $hymn->id) }}" wire:navigate class="btn-ghost btn-sm" aria-label="Edit"><x-admin.icon name="edit" class="size-4" /></a>
                                <button type="button" class="btn-ghost btn-sm text-red-700" aria-label="Delete"
                                        @click="$store.confirm.ask({ title: 'Delete hymn {{ $hymn->number }}?', message: 'This removes the hymn, its stanzas, solfa and audio from the app. Members\' favourites and programs referencing it will drop it.', confirmLabel: 'Delete hymn', onConfirm: () => $wire.delete('{{ $hymn->id }}') })">
                                    <x-admin.icon name="trash" class="size-4" />
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No hymns yet" icon="music" colspan="6">Add hymns with lyrics, solfa and audio. Only published hymns appear in the app.</x-admin.empty>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($hymns->hasPages())<div class="border-t border-line px-4 py-3">{{ $hymns->links() }}</div>@endif
    </div>
</div>
