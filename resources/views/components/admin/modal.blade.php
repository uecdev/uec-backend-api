@props(['show', 'title', 'size' => 'max-w-2xl'])
{{-- Livewire-bound modal: <x-admin.modal show="showForm" title="…"> … <x-slot:footer>…</x-slot:footer></x-admin.modal> --}}
<div x-data="{ open: $wire.entangle('{{ $show }}') }" x-cloak x-show="open"
     class="fixed inset-0 z-40 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true"
     @keydown.escape.window="open = false">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-brand-950/50" @click="open = false"></div>
    <div x-show="open" x-transition x-trap.noscroll="open"
         class="card relative flex max-h-[92vh] w-full {{ $size }} flex-col rounded-b-none sm:rounded-2xl">
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-lg font-black">{{ $title }}</h2>
            <button type="button" class="rounded-lg p-1.5 text-muted hover:bg-cream hover:text-ink" @click="open = false" aria-label="Close">
                <x-admin.icon name="x" />
            </button>
        </div>
        <div class="overflow-y-auto px-6 py-5">{{ $slot }}</div>
        @if (isset($footer))
            <div class="flex flex-wrap justify-end gap-2 border-t border-line bg-cream/40 px-6 py-4">{{ $footer }}</div>
        @endif
    </div>
</div>
