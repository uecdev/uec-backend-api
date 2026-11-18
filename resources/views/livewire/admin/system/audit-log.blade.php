@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header title="Audit log" description="An append-only record of administrative actions, security events and gateway-driven changes. Secrets and codes are redacted." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
            <x-admin.search placeholder="Action, description, actor or subject ID" />
            <select wire:model.live="area" class="input sm:ml-auto sm:w-64" aria-label="Area">
                <option value="">All areas</option>@foreach ($areas as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <ul class="divide-y divide-line" wire:loading.class="opacity-60">
            @forelse ($entries as $e)
                <li wire:key="al-{{ $e->id }}" class="px-5 py-3">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                        <div class="min-w-0">
                            <span @class(['font-mono text-xs font-bold', 'text-red-700' => str_starts_with($e->action, 'security.'), 'text-brand-700' => ! str_starts_with($e->action, 'security.')])>{{ $e->action }}</span>
                            @if ($e->description)<span class="text-sm text-ink"> — {{ $e->description }}</span>@endif
                            @if ($e->subject_type)<span class="text-xs text-muted"> · {{ $e->subject_type }} {{ Str::limit($e->subject_id, 12, '…') }}</span>@endif
                        </div>
                        <div class="shrink-0 text-xs text-muted">
                            {{ $e->actor?->displayName() ?? ucfirst($e->actor_type) }} · {{ $e->created_at->timezone($tz)->format('j M Y, H:i:s') }} @if ($e->ip)· {{ $e->ip }}@endif
                            @if ($e->properties)<button type="button" class="ml-1 font-bold text-brand-700 hover:underline" wire:click="$set('expanded', '{{ $expanded === $e->id ? '' : $e->id }}')">{{ $expanded === $e->id ? 'Hide' : 'Details' }}</button>@endif
                        </div>
                    </div>
                    @if ($expanded === $e->id && $e->properties)
                        <pre class="mt-2 max-h-64 overflow-auto rounded-xl bg-cream p-3 text-xs">{{ json_encode($e->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    @endif
                </li>
            @empty
                <li><x-admin.empty title="No audit entries" icon="list" /></li>
            @endforelse
        </ul>
        @if ($entries->hasPages())<div class="border-t border-line px-4 py-3">{{ $entries->links() }}</div>@endif
    </div>
</div>
