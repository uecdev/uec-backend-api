<div>
    <x-admin.page-header title="Subscription plans" description="Prices shown on the paywall. A price change applies to new checkouts and to existing subscribers only when they renew onto a re-synced plan." />

    <div class="mb-5 rounded-xl border border-gold-100 bg-gold-50 px-4 py-3 text-sm text-gold-700">
        <strong>Store policy decision pending.</strong> Selling digital content through Paystack/Monnify inside the iOS/Android app risks store rejection (Apple 3.1.1, Google Play Payments). See docs/backend-handoff.md §E.1.
    </div>

    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
        @foreach ($plans as $p)
            <article @class(['card flex flex-col', 'ring-2 ring-brand-600' => $p->highlighted]) wire:key="plan-{{ $p->id }}">
                <div class="flex-1 p-5">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-lg font-black">{{ $p->name }}</h2>
                        <div class="flex flex-col items-end gap-1">
                            @if ($p->savings_percent)<span class="badge bg-brand-700 text-white">Save {{ $p->savings_percent }}%</span>@endif
                            @unless ($p->is_active)<span class="badge bg-slate-100 text-slate-700">Hidden</span>@endunless
                        </div>
                    </div>
                    <div class="mt-3 text-3xl font-black tabular-nums">{{ $p->formattedAmount() }}</div>
                    <div class="text-sm text-muted">every {{ $p->interval_months === 1 ? 'month' : $p->interval_months.' months' }}</div>
                    <dl class="mt-5 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted">Plan ID</dt><dd class="font-mono text-xs">{{ $p->id }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">Live subscriptions</dt><dd class="font-bold">{{ number_format($p->live_count) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Paystack plan</dt><dd class="font-mono text-xs">{{ $p->paystack_plan_code ?? 'Not synced' }}</dd></div>
                    </dl>
                </div>
                @can('finance.manage')
                    <div class="flex gap-2 border-t border-line p-3">
                        <button type="button" class="btn-secondary flex-1" wire:click="edit('{{ $p->id }}')"><x-admin.icon name="edit" class="size-4" /> Edit</button>
                        @if ($paystackReady && ! $p->paystack_plan_code)
                            <button type="button" class="btn-ghost" wire:click="syncPaystack('{{ $p->id }}')" wire:loading.attr="disabled" title="Create this plan on Paystack"><x-admin.icon name="refresh" class="size-4" /> Paystack</button>
                        @endif
                    </div>
                @endcan
            </article>
        @endforeach
    </div>
    <p class="mt-4 text-xs text-muted">Without a Paystack plan code, Paystack checkouts still work: renewals are charged by the scheduler against the saved card authorization. Monnify renewals always work this way.</p>

    <x-admin.modal show="showForm" title="Edit plan" size="max-w-lg">
        <div class="space-y-4">
            <x-admin.field label="Display name" for="pname" error="name" required><input id="pname" type="text" wire:model="name" class="input"></x-admin.field>
            <x-admin.field label="Price (₦)" for="pamount" error="amount" required help="Changing the price clears the Paystack plan code; sync again afterwards."><input id="pamount" type="text" inputmode="decimal" wire:model="amount" class="input"></x-admin.field>
            <div class="grid grid-cols-2 gap-4">
                <x-admin.field label="Savings badge (%)" for="psave" error="savings_percent"><input id="psave" type="number" wire:model="savings_percent" class="input"></x-admin.field>
                <x-admin.field label="Sort order" for="psort" error="sort"><input id="psort" type="number" wire:model="sort" class="input"></x-admin.field>
            </div>
            <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="highlighted" class="size-4 rounded border-line text-brand-700"> Highlight on the paywall (recommended plan)</label>
            <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="is_active" class="size-4 rounded border-line text-brand-700"> Available for new checkouts</label>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" wire:click="save">Save plan</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
