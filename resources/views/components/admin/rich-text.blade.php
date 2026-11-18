@props(['model', 'label', 'rows' => 6, 'help' => null, 'required' => false])
@php $id = 'rt-'.str_replace('.', '-', $model); @endphp
{{-- Editor for the app's restricted markup with a live, escaped preview. --}}
<div x-data="{ preview: false }">
    <div class="mb-1.5 flex items-center justify-between">
        <label for="{{ $id }}" class="label mb-0">{{ $label }} @if ($required)<span class="text-red-700" aria-hidden="true">*</span>@endif</label>
        <div class="flex rounded-lg border border-line bg-cream p-0.5 text-xs" role="tablist">
            <button type="button" role="tab" :aria-selected="!preview" class="rounded-md px-2 py-1 font-bold" :class="!preview ? 'bg-white shadow-sm text-ink' : 'text-muted'" @click="preview = false">Write</button>
            <button type="button" role="tab" :aria-selected="preview" class="rounded-md px-2 py-1 font-bold" :class="preview ? 'bg-white shadow-sm text-ink' : 'text-muted'" @click="preview = true">Preview</button>
        </div>
    </div>
    <textarea x-show="!preview" id="{{ $id }}" wire:model.blur="{{ $model }}" rows="{{ $rows }}" @class(['input font-[450] leading-relaxed', 'input-error' => $errors->has($model)])></textarea>
    <div x-show="preview" x-cloak class="prose-uec min-h-24 rounded-xl border border-line bg-cream/40 px-4 py-3 text-sm">
        {!! \App\Support\RichText::toHtml(data_get($this, $model)) ?: '<p class="text-muted">Nothing to preview.</p>' !!}
    </div>
    @error($model)<p class="error">{{ $message }}</p>@enderror
    @foreach (\App\Support\RichText::warnings(data_get($this, $model)) as $warning)
        <p class="mt-1.5 text-xs font-bold text-gold-700">{{ $warning }}</p>
    @endforeach
    <p class="help">{{ $help ?? 'Blank line = new paragraph · **bold** · *italic* · [v.1-4](bible:Joshua 1:1-4) · [label](https://…)' }}</p>
</div>
