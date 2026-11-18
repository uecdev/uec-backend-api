@props(['field', 'sortField', 'sortDirection', 'label'])
<th scope="col" aria-sort="{{ $sortField === $field ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <button type="button" wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-1 uppercase hover:text-ink">
        {{ $label }}
        @if ($sortField === $field)
            <x-admin.icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3.5" />
        @else
            <x-admin.icon name="up-down" class="size-3.5 opacity-40" />
        @endif
    </button>
</th>
