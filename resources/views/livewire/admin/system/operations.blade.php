@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header title="Operations" description="Health of the platform’s moving parts: database, cache, queue workers, scheduler, storage, background jobs and payment webhooks." />

    <div class="mb-4 inline-flex rounded-xl border border-line bg-cream p-1" role="tablist">
        <button type="button" role="tab" wire:click="$set('tab', 'health')" @class(['tab', 'tab-active' => $tab === 'health'])>Health</button>
        <button type="button" role="tab" wire:click="$set('tab', 'jobs')" @class(['tab', 'tab-active' => $tab === 'jobs'])>Failed jobs @if ($failedCount)<span class="badge ml-1 bg-red-50 text-red-700">{{ $failedCount }}</span>@endif</button>
        <button type="button" role="tab" wire:click="$set('tab', 'webhooks')" @class(['tab', 'tab-active' => $tab === 'webhooks'])>Webhooks @if ($failedWebhooks)<span class="badge ml-1 bg-red-50 text-red-700">{{ $failedWebhooks }}</span>@endif</button>
    </div>

    @if ($tab === 'health')
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" wire:poll.30s>
            @foreach ($checks as $key => $c)
                <div class="card flex items-start gap-4 p-5" wire:key="hc-{{ $key }}">
                    <span @class(['grid size-10 shrink-0 place-items-center rounded-full', 'bg-brand-50 text-brand-700' => $c['ok'], 'bg-red-50 text-red-700' => ! $c['ok']])>
                        <x-admin.icon :name="$c['ok'] ? 'check' : 'alert'" class="size-5" />
                    </span>
                    <div><div class="font-black">{{ $c['label'] }}</div><div @class(['text-sm', 'text-muted' => $c['ok'], 'text-red-700' => ! $c['ok']])>{{ $c['detail'] }}</div></div>
                </div>
            @endforeach
        </div>
        <section class="card mt-6">
            <div class="card-header"><h2 class="card-title">Queue backlog</h2><span class="text-xs text-muted">Refreshes every 30 s</span></div>
            <dl class="grid grid-cols-2 gap-px bg-line sm:grid-cols-4">
                @foreach ($queues as $name => $size)
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">{{ $name }}</dt><dd class="text-2xl font-black tabular-nums">{{ number_format($size) }}</dd></div>
                @endforeach
            </dl>
            <p class="border-t border-line px-5 py-3 text-xs text-muted">Workers should run <code>php artisan queue:work redis --queue=otp,webhooks,notifications,default</code> under Supervisor (see docs/DEPLOYMENT.md).</p>
        </section>
    @elseif ($tab === 'jobs')
        <div class="card">
            <div class="card-header"><h2 class="card-title">Failed jobs</h2>@if ($failedCount)<button type="button" class="btn-secondary btn-sm" wire:click="retryAll" wire:confirm="Retry every failed job?"><x-admin.icon name="refresh" class="size-3.5" /> Retry all</button>@endif</div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Failed</th><th>Job</th><th>Queue</th><th>Error</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @forelse ($failedJobs as $job)
                        @php $payload = json_decode($job->payload, true); @endphp
                        <tr wire:key="fj-{{ $job->uuid }}">
                            <td class="whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->timezone($tz)->format('j M, H:i') }}</td>
                            <td class="font-mono text-xs">{{ class_basename($payload['displayName'] ?? 'Job') }}</td>
                            <td>{{ $job->queue }}</td>
                            <td class="max-w-md"><span class="line-clamp-2 text-xs text-red-700" title="{{ Str::limit($job->exception, 1000) }}">{{ Str::before($job->exception, "\n") }}</span></td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" class="btn-ghost btn-sm" wire:click="retryJob('{{ $job->uuid }}')">Retry</button>
                                <button type="button" class="btn-ghost btn-sm text-red-700" wire:click="forgetJob('{{ $job->uuid }}')" wire:confirm="Delete this failed job?">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <x-admin.empty title="No failed jobs" icon="check" colspan="5" />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($failedJobs->hasPages())<div class="border-t border-line px-4 py-3">{{ $failedJobs->links() }}</div>@endif
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <div><h2 class="card-title">Payment webhooks</h2><p class="text-xs text-muted">Signature-verified events, de-duplicated by provider event key.</p></div>
                <select wire:model.live="webhookStatus" class="input w-40" aria-label="Status"><option value="">All statuses</option><option value="received">Received</option><option value="processed">Processed</option><option value="ignored">Ignored</option><option value="failed">Failed</option></select>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Received</th><th>Provider</th><th>Event</th><th>Reference</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @forelse ($webhooks as $w)
                        <tr wire:key="wh-{{ $w->id }}">
                            <td class="whitespace-nowrap text-muted">{{ $w->created_at->timezone($tz)->format('j M, H:i:s') }}</td>
                            <td>{{ ucfirst($w->provider) }}</td>
                            <td class="font-mono text-xs">{{ $w->type }}</td>
                            <td class="font-mono text-xs">{{ $w->reference ?? '—' }}</td>
                            <td><x-admin.status :value="$w->status" />@if ($w->error)<span class="block max-w-64 truncate text-xs text-red-700" title="{{ $w->error }}">{{ $w->error }}</span>@endif</td>
                            <td class="text-right">@if ($w->status === 'failed')<button type="button" class="btn-ghost btn-sm" wire:click="reprocessWebhook('{{ $w->id }}')">Reprocess</button>@endif</td>
                        </tr>
                    @empty
                        <x-admin.empty title="No webhooks received yet" icon="webhook" colspan="6" />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($webhooks->hasPages())<div class="border-t border-line px-4 py-3">{{ $webhooks->links() }}</div>@endif
        </div>
    @endif
</div>
