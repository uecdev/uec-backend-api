@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header title="Integrations" description="Third-party services. Credentials live only in the server environment; this page reports whether each is configured, how it has performed over 7 days, and lets you run a harmless live test." />

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($integrations as $key => $i)
            @php $s = $stats[$key]; $r = $results[$key] ?? null; @endphp
            <article class="card flex flex-col" wire:key="int-{{ $key }}">
                <div class="flex-1 p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="font-black">{{ $i['name'] }}</h2>
                            <p class="text-xs text-muted">{{ $i['purpose'] }}</p>
                        </div>
                        @if ($i['configured'])<span class="badge bg-brand-50 text-brand-700">Configured</span>@else<span class="badge bg-gold-50 text-gold-700">Not configured</span>@endif
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-lg bg-cream px-2 py-2"><dt class="text-muted">Calls</dt><dd class="text-base font-black">{{ number_format($s['calls']) }}</dd></div>
                        <div class="rounded-lg bg-cream px-2 py-2"><dt class="text-muted">Success</dt><dd class="text-base font-black">{{ $s['success_rate'] !== null ? $s['success_rate'].'%' : '—' }}</dd></div>
                        <div class="rounded-lg bg-cream px-2 py-2"><dt class="text-muted">Avg</dt><dd class="text-base font-black">{{ $s['avg_ms'] !== null ? $s['avg_ms'].'ms' : '—' }}</dd></div>
                    </dl>
                    @if ($s['last_error'])<p class="mt-3 line-clamp-2 text-xs text-red-700" title="{{ $s['last_error'] }}">Last error: {{ $s['last_error'] }}</p>@endif
                    @unless ($i['configured'])<p class="mt-3 text-xs text-muted">Set: <span class="font-mono">{{ implode(', ', $i['env']) }}</span></p>@endunless
                    @if ($r)
                        <div @class(['mt-3 rounded-lg px-3 py-2 text-xs font-bold', 'bg-brand-50 text-brand-800' => $r['ok'], 'bg-red-50 text-red-800' => ! $r['ok']]) role="status">{{ $r['at'] }} — {{ $r['message'] }}</div>
                    @endif
                </div>
                <div class="border-t border-line p-3">
                    @if ($key === 'zeptomail')
                        <div class="mb-2"><input type="email" wire:model="testEmail" class="input py-2 text-xs" aria-label="Send test email to" placeholder="Send test email to…">@error('testEmail')<p class="error">{{ $message }}</p>@enderror</div>
                    @endif
                    <button type="button" class="btn-secondary w-full" wire:click="test('{{ $key }}')" wire:loading.attr="disabled" wire:target="test('{{ $key }}')" @disabled(! $i['configured'])>
                        <span wire:loading.remove wire:target="test('{{ $key }}')">{{ $key === 'zeptomail' ? 'Send test email' : 'Test connection' }}</span>
                        <span wire:loading wire:target="test('{{ $key }}')">Testing…</span>
                    </button>
                </div>
            </article>
        @endforeach
    </div>

    <section class="card mt-8">
        <div class="card-header">
            <div><h2 class="card-title">Recent integration calls</h2><p class="text-xs text-muted">Bodies and secrets are never logged.</p></div>
            <div class="flex gap-2">
                <select wire:model.live="logIntegration" class="input w-44" aria-label="Integration"><option value="">All integrations</option>@foreach ($integrations as $k => $i)<option value="{{ $k }}">{{ $i['name'] }}</option>@endforeach</select>
                <select wire:model.live="logOutcome" class="input w-32" aria-label="Outcome"><option value="">All</option><option value="ok">Success</option><option value="fail">Failed</option></select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>When</th><th>Integration</th><th>Operation</th><th>HTTP</th><th>Time</th><th>Result</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr wire:key="il-{{ $log->id }}">
                        <td class="whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($log->created_at)->timezone($tz)->format('j M, H:i:s') }}</td>
                        <td class="font-bold">{{ $integrations[$log->integration]['name'] ?? $log->integration }}</td>
                        <td class="font-mono text-xs">{{ $log->method }} {{ $log->operation }}@if ($log->correlation_id)<span class="block text-muted">{{ $log->correlation_id }}</span>@endif</td>
                        <td class="tabular-nums">{{ $log->status_code ?? '—' }}</td>
                        <td class="tabular-nums text-muted">{{ $log->duration_ms }}ms</td>
                        <td>@if ($log->success)<x-admin.status value="success" />@else<x-admin.status value="failed" /><span class="block max-w-64 truncate text-xs text-red-700" title="{{ $log->error }}">{{ $log->error }}</span>@endif</td>
                    </tr>
                @empty
                    <x-admin.empty title="No calls logged yet" icon="plug" colspan="6" />
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())<div class="border-t border-line px-4 py-3">{{ $logs->links() }}</div>@endif
    </section>
</div>
