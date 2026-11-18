@props(['label' => null, 'for' => null, 'error' => null, 'help' => null, 'required' => false])
<div {{ $attributes->merge(['class' => '']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="label">
            {{ $label }} @if ($required)<span class="text-red-700" aria-hidden="true">*</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($error)
        @error($error)<p class="error" role="alert">{{ $message }}</p>@enderror
    @endif
    @if ($help)<p class="help">{{ $help }}</p>@endif
</div>
