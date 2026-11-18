@props(['value'])
@php
    $value = $value instanceof \BackedEnum ? $value->value : (string) $value;
    $styles = [
        'success' => 'bg-brand-50 text-brand-700', 'active' => 'bg-brand-50 text-brand-700', 'sent' => 'bg-brand-50 text-brand-700',
        'processed' => 'bg-brand-50 text-brand-700', 'resolved' => 'bg-brand-50 text-brand-700', 'published' => 'bg-brand-50 text-brand-700',
        'pending' => 'bg-gold-50 text-gold-700', 'scheduled' => 'bg-gold-50 text-gold-700', 'sending' => 'bg-gold-50 text-gold-700',
        'in_review' => 'bg-gold-50 text-gold-700', 'past_due' => 'bg-gold-50 text-gold-700', 'received' => 'bg-gold-50 text-gold-700',
        'new' => 'bg-sky-50 text-sky-800', 'draft' => 'bg-slate-100 text-slate-700', 'cancelled' => 'bg-slate-100 text-slate-700',
        'abandoned' => 'bg-slate-100 text-slate-700', 'archived' => 'bg-slate-100 text-slate-700', 'ignored' => 'bg-slate-100 text-slate-700',
        'skipped' => 'bg-slate-100 text-slate-700', 'expired' => 'bg-slate-100 text-slate-700',
        'failed' => 'bg-red-50 text-red-700', 'refunded' => 'bg-red-50 text-red-700', 'suspended' => 'bg-red-50 text-red-700',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.($styles[$value] ?? 'bg-slate-100 text-slate-700')]) }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ ucfirst(str_replace('_', ' ', $value)) }}
</span>
