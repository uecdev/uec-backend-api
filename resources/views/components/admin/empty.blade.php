@props(['title' => 'Nothing here yet', 'icon' => 'info', 'colspan' => null])
@if ($colspan)
    <tr><td colspan="{{ $colspan }}">
@endif
<div class="flex flex-col items-center px-6 py-14 text-center">
    <div class="grid size-12 place-items-center rounded-2xl bg-cream text-muted"><x-admin.icon :name="$icon" /></div>
    <p class="mt-3 font-black text-ink">{{ $title }}</p>
    @if ($slot->isNotEmpty())<div class="mt-1 max-w-sm text-sm text-muted">{{ $slot }}</div>@endif
</div>
@if ($colspan)
    </td></tr>
@endif
