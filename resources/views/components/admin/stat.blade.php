@props(['label', 'value', 'hint' => null, 'icon' => null, 'trend' => null, 'source' => null])
{{-- KPI tile. $source labels where a figure comes from (e.g. "Estimate", "Measured"). --}}
<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="text-[13px] font-bold text-muted">{{ $label }}</div>
        @if ($icon)
            <div class="grid size-9 place-items-center rounded-xl bg-brand-50 text-brand-700"><x-admin.icon :name="$icon" class="size-[18px]" /></div>
        @endif
    </div>
    <div class="mt-2 text-[28px] leading-none font-black tracking-tight text-ink tabular-nums">{{ $value }}</div>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        @if ($trend !== null)
            <span @class(['badge', 'bg-brand-50 text-brand-700' => $trend >= 0, 'bg-red-50 text-red-700' => $trend < 0])>
                {{ $trend >= 0 ? '▲' : '▼' }} {{ number_format(abs($trend), 1) }}%
            </span>
        @endif
        @if ($hint)<span class="text-muted">{{ $hint }}</span>@endif
        @if ($source)<span class="badge bg-gold-50 text-gold-700">{{ $source }}</span>@endif
    </div>
</div>
