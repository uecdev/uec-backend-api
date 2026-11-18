@php $tz = config('uec.timezone'); $naira = fn ($k) => '₦'.number_format($k / 100, 2); @endphp
<div>
    <x-admin.page-header title="Payments" description="Every checkout and renewal, with the gateway’s verdict. Amounts are always taken from the plan, never from the app.">
        <x-slot:actions>
            @can('reports.export')<button type="button" wire:click="export" class="btn-secondary"><x-admin.icon name="download" class="size-4" /> Export CSV</button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-admin.stat label="Collected (30 days)" :value="$naira($summary['success_30d'])" :hint="number_format($summary['count_30d']).' successful payments'" />
        <x-admin.stat label="Failed (30 days)" :value="number_format($summary['failed_30d'])" />
        <x-admin.stat label="Refunded (30 days)" :value="number_format($summary['refunded_30d'])" />
        <div class="card p-5">
            <div class="text-[13px] font-bold text-muted">Needs reconciliation</div>
            <div class="mt-2 text-[28px] leading-none font-black">{{ number_format($summary['stale_pending']) }}</div>
            <div class="mt-2 flex items-center justify-between gap-2 text-xs text-muted">
                <span>Pending &gt; 30 min</span>
                @can('finance.manage')@if ($summary['stale_pending'])<button type="button" wire:click="reverifyStale" wire:loading.attr="disabled" class="btn-secondary btn-sm"><x-admin.icon name="refresh" class="size-3.5" /> Re-verify</button>@endif @endcan
            </div>
        </div>
    </div>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 xl:flex-row xl:items-center">
            <x-admin.search placeholder="Reference, transaction ID or member" />
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:ml-auto">
                <select wire:model.live="filterStatus" class="input" aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\PaymentStatus::cases() as $s)<option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>@endforeach
                </select>
                <select wire:model.live="filterProvider" class="input" aria-label="Provider">
                    <option value="">All providers</option><option value="paystack">Paystack</option><option value="monnify">Monnify</option>
                </select>
                <input type="date" wire:model.live="filterFrom" class="input" aria-label="From date">
                <input type="date" wire:model.live="filterTo" class="input" aria-label="To date">
            </div>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead><tr>
                    <th>Reference</th><th>Member</th><th>Plan</th>
                    <x-admin.sort-header field="amount_kobo" label="Amount" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th>Provider</th><th>Status</th>
                    <x-admin.sort-header field="created_at" label="Created" :sort-field="$sortField ?: 'created_at'" :sort-direction="$sortDirection" />
                </tr></thead>
                <tbody>
                @forelse ($payments as $p)
                    <tr wire:key="p-{{ $p->id }}" class="cursor-pointer" wire:click="show('{{ $p->id }}')">
                        <td class="font-mono text-xs">{{ $p->reference }}</td>
                        <td class="max-w-48 truncate">{{ $p->user?->displayName() ?? 'Deleted account' }}</td>
                        <td>{{ $p->plan?->name ?? '—' }}@if ($p->purpose === 'renewal')<span class="block text-xs text-muted">Renewal</span>@endif</td>
                        <td class="font-bold tabular-nums">{{ $naira($p->amount_kobo) }}</td>
                        <td>{{ ucfirst($p->provider) }}</td>
                        <td><x-admin.status :value="$p->status" /></td>
                        <td class="whitespace-nowrap text-muted">{{ $p->created_at->timezone($tz)->format('j M Y, H:i') }}</td>
                    </tr>
                @empty
                    <x-admin.empty title="No payments match" icon="card" colspan="7" />
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($payments->hasPages())<div class="border-t border-line px-4 py-3">{{ $payments->links() }}</div>@endif
    </div>

    <x-admin.modal show="showDetail" title="Payment details">
        @if ($selected)
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-muted">Reference</dt><dd class="font-mono text-xs break-all">{{ $selected->reference }}</dd></div>
                <div><dt class="text-xs text-muted">Status</dt><dd><x-admin.status :value="$selected->status" /></dd></div>
                <div><dt class="text-xs text-muted">Amount</dt><dd class="font-black">{{ $naira($selected->amount_kobo) }} {{ $selected->currency }}</dd></div>
                <div><dt class="text-xs text-muted">Provider / channel</dt><dd>{{ ucfirst($selected->provider) }} {{ $selected->channel ? '· '.$selected->channel : '' }}</dd></div>
                <div><dt class="text-xs text-muted">Member</dt><dd>@if ($selected->user)<a href="{{ route('admin.users.show', $selected->user->id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $selected->user->displayName() }}</a>@else Deleted account @endif</dd></div>
                <div><dt class="text-xs text-muted">Plan / purpose</dt><dd>{{ $selected->plan?->name ?? '—' }} · {{ $selected->purpose }}</dd></div>
                <div><dt class="text-xs text-muted">Gateway transaction</dt><dd class="font-mono text-xs break-all">{{ $selected->provider_transaction_id ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">Gateway message</dt><dd>{{ $selected->gateway_response ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">Created</dt><dd>{{ $selected->created_at->timezone($tz)->format('j M Y, H:i:s') }}</dd></div>
                <div><dt class="text-xs text-muted">Paid</dt><dd>{{ $selected->paid_at?->timezone($tz)->format('j M Y, H:i:s') ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">Last verified with gateway</dt><dd>{{ $selected->last_verified_at?->diffForHumans() ?? 'Never' }}</dd></div>
                <div><dt class="text-xs text-muted">Refunded</dt><dd>{{ $selected->refunded_at?->timezone($tz)->format('j M Y, H:i') ?? '—' }}</dd></div>
            </dl>
            <div class="mt-6">
                <div class="mb-2 text-sm font-bold">Webhook events</div>
                <ul class="divide-y divide-line rounded-xl border border-line text-sm">
                    @forelse ($webhooks as $w)
                        <li class="flex items-center justify-between px-4 py-2"><span class="font-mono text-xs">{{ $w->type }}</span><span class="flex items-center gap-2"><x-admin.status :value="$w->status" /><span class="text-xs text-muted">{{ $w->created_at->diffForHumans() }}</span></span></li>
                    @empty
                        <li class="px-4 py-3 text-muted">No webhooks received for this reference.</li>
                    @endforelse
                </ul>
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Close</button>
            @if ($selected)
                @can('finance.manage')
                    @if ($selected->status === \App\Enums\PaymentStatus::Success)
                        <button type="button" class="btn-danger" @click="$store.confirm.ask({ title: 'Record a refund?', message: 'Use this only after the refund was issued at the gateway. Premium access is revoked immediately.', confirmLabel: 'Mark refunded', onConfirm: () => $wire.markRefunded('{{ $selected->id }}') })">Mark refunded</button>
                    @endif
                    <button type="button" class="btn-primary" wire:click="reverify('{{ $selected->id }}')" wire:loading.attr="disabled"><x-admin.icon name="refresh" class="size-4" /> Re-verify with gateway</button>
                @endcan
            @endif
        </x-slot:footer>
    </x-admin.modal>
</div>
