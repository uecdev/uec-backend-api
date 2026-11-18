@props(['title', 'description' => null, 'back' => null])
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="mb-2 inline-flex items-center gap-1.5 text-sm font-bold text-muted hover:text-brand-700">
                <x-admin.icon name="arrow-left" class="size-4" /> Back
            </a>
        @endif
        <h1 class="text-2xl font-black tracking-tight text-ink sm:text-[28px]">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 max-w-3xl text-sm text-muted">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
