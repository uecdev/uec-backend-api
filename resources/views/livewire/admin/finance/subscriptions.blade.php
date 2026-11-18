@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header title="Subscriptions" description="Premium access is granted while a subscription is active, cancelled but within its paid period, or past due within the grace period." />

    @if ($unsynced)
        <div class="mb-5 rounded-xl border border-gold-100 bg-gold-50 px-4 py-3 text-sm text-gold-700"><strong>{{ $unsynced }}</strong> subscription change(s) are waiting to sync with the gateway; retries run automatically.</div>
    @endif

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 xl:flex-row xl:items-center">
            <x-admin.search placeholder="Member, subscription code or ID" />
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:ml-auto">
                <select wire:model.live="filterStatus" class="input" aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\SubscriptionStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                </select>
                <select wire:model.live="filterPlan" class="input" aria-label="Plan">
                    <option value="">All plans</option>@foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterProvider" class="input" aria-label="Provider">
                    <option value="">All providers</option><option value="paystack">Paystack</option><option value="monnify">Monnify</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead><tr>
                    <th>Member</th><th>Plan</th><th>Status</th><th>Provider</th>
                    <x-admin.sort-header field="current_period_end" label="Period ends" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th>Gateway</th><th class="text-right"><span class="sr-only">Actions</span></th>
                </tr></thead>
                <tbody>
                @forelse ($subscriptions as $s)
                    <tr wire:key="s-{{ $s->id }}">
                        <td class="max-w-48">@if ($s->user)<a href="{{ route('admin.users.show', $s->user->id) }}" wire:navigate class="block truncate font-bold hover:text-brand-700">{{ $s->user->displayName() }}</a>@else<span class="text-muted">Deleted account</span>@endif</td>
                        <td>{{ $s->plan->name }}@if ($s->pendingPlan)<span class="block text-xs text-gold-700">→ {{ $s->pendingPlan->name }} on renewal</span>@endif</td>
                        <td><x-admin.status :value="$s->status" /></td>
                        <td>{{ ucfirst($s->provider) }}</td>
                        <td class="whitespace-nowrap">{{ $s->current_period_end->timezone($tz)->format('j M Y') }}@if ($s->access_until && $s->status === \App\Enums\SubscriptionStatus::Cancelled)<span class="block text-xs text-muted">access until {{ $s->access_until->timezone($tz)->format('j M') }}</span>@endif</td>
                        <td class="text-xs">@if ($s->gateway_synced_at)<span class="text-brand-700">Synced</span>@elseif (in_array($s->status->value, ['active', 'cancelled']))<span class="text-gold-700">Pending</span>@else<span class="text-muted">—</span>@endif</td>
                        <td class="text-right whitespace-nowrap">
                            @can('finance.manage')
                                @if (in_array($s->status->value, ['active', 'past_due']))
                                    <button type="button" class="btn-ghost btn-sm text-red-700" @click="$store.confirm.ask({ title: 'Stop auto-renewal?', message: 'The member keeps premium access until the end of the paid period, then it expires. Auto-renew is disabled at the gateway.', confirmLabel: 'Cancel subscription', onConfirm: () => $wire.cancel('{{ $s->id }}') })">Cancel</button>
                                @endif
                                @if (! $s->gateway_synced_at && in_array($s->status->value, ['active', 'cancelled']))
                                    <button type="button" class="btn-ghost btn-sm" wire:click="resync('{{ $s->id }}')">Retry sync</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No subscriptions match" icon="repeat" colspan="7" />
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($subscriptions->hasPages())<div class="border-t border-line px-4 py-3">{{ $subscriptions->links() }}</div>@endif
    </div>
</div>
