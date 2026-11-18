@props(['placeholder' => 'Search…', 'model' => 'search'])
<div class="relative w-full sm:w-72">
    <x-admin.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
    <input type="search" wire:model.live.debounce.350ms="{{ $model }}" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
           {{ $attributes->merge(['class' => 'input pl-9']) }}>
</div>
