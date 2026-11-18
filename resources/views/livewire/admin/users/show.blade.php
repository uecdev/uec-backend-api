@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header :title="$user->name ?: ($user->trashed() ? 'Deleted account' : 'Unnamed member')" :back="route('admin.users.index')">
        <x-slot:actions>
            @can('users.manage')
                @unless ($user->trashed())
                    <button type="button" class="btn-secondary"
                            @click="$store.confirm.ask({ title: 'Sign out everywhere?', message: 'All of this member\'s sessions will be revoked. They will need to sign in again on every device.', confirmLabel: 'Sign out everywhere', danger: false, onConfirm: () => $wire.signOutEverywhere() })">
                        <x-admin.icon name="logout" class="size-4" /> Sign out everywhere
                    </button>
                    @if ($user->suspended_at)
                        <button type="button" class="btn-primary" wire:click="reactivate"><x-admin.icon name="check" class="size-4" /> Reactivate</button>
                    @elseif (! $user->hasRole('super_admin') && $user->id !== auth()->id())
                        <button type="button" class="btn-danger" wire:click="$set('showSuspend', true)"><x-admin.icon name="lock" class="size-4" /> Suspend</button>
                    @endif
                @endunless
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    @if ($user->suspended_at)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="status">
            <strong>Suspended</strong> {{ $user->suspended_at->diffForHumans() }} — {{ $user->suspension_reason }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6">
            <section class="card p-5">
                <div class="flex items-center gap-4">
                    @if ($user->avatar_url)
                        <img src="{{ $user->avatar_url }}" alt="" class="size-16 rounded-full object-cover">
                    @else
                        <span class="grid size-16 place-items-center rounded-full bg-brand-50 text-2xl font-black text-brand-700">{{ strtoupper(mb_substr($user->displayName(), 0, 1)) }}</span>
                    @endif
                    <div class="min-w-0">
                        <div class="truncate text-lg font-black">{{ $user->name ?: '—' }}</div>
                        <div class="text-xs text-muted">ID <span class="font-mono" x-data="clipboard('{{ $user->id }}')"><button type="button" @click="copy" class="hover:text-ink" :title="copied ? 'Copied' : 'Copy'">{{ $user->id }}</button></span></div>
                    </div>
                </div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Email</dt><dd class="truncate text-right font-bold">{{ $user->email ?? '—' }} @if ($user->email_verified_at)<x-admin.icon name="check" class="inline size-4 text-brand-600" />@endif</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Phone</dt><dd class="text-right font-bold">{{ $user->phone ?? '—' }} @if ($user->phone_verified_at)<x-admin.icon name="check" class="inline size-4 text-brand-600" />@endif</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Google sign-in</dt><dd class="font-bold">{{ $user->google_sub ? 'Linked' : 'No' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Language</dt><dd class="font-bold uppercase">{{ $user->locale }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Joined</dt><dd class="font-bold">{{ $user->created_at->timezone($tz)->format('j M Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Last sign-in</dt><dd class="font-bold">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Last seen</dt><dd class="font-bold">{{ $user->last_seen_at?->diffForHumans() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Roles</dt><dd class="text-right font-bold">{{ $user->getRoleNames()->map(fn ($r) => \App\Support\Permissions::ROLE_LABELS[$r] ?? $r)->join(', ') ?: 'Member' }}</dd></div>
                </dl>
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Engagement</h2></div>
                <dl class="grid grid-cols-2 gap-px bg-line text-sm">
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">Active days (30d)</dt><dd class="text-xl font-black">{{ $activeDays30 }}</dd></div>
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">Devotional reads</dt><dd class="text-xl font-black">{{ number_format($readsCount) }}</dd></div>
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">Favourites</dt><dd class="text-xl font-black">{{ number_format($favouritesCount) }}</dd></div>
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">Hymn programs</dt><dd class="text-xl font-black">{{ number_format($programsCount) }}</dd></div>
                </dl>
                <div class="px-5 py-4">
                    <div class="mb-2 text-xs font-bold text-muted uppercase">Recent active days</div>
                    @forelse ($recentActivity as $day)
                        <div class="flex justify-between py-1 text-sm"><span>{{ $day->date->format('D j M') }}</span><span class="text-muted">{{ $day->platform ? ($day->platform === 'ios' ? 'iOS' : ucfirst($day->platform)) : '' }} {{ $day->app_version ? 'v'.$day->app_version : '' }}</span></div>
                    @empty
                        <p class="text-sm text-muted">No signed-in activity recorded.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="space-y-6 xl:col-span-2">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Subscription</h2>
                    @if ($entitlements)<span class="badge bg-gold-50 text-gold-700">Premium</span>@else<span class="badge bg-slate-100 text-slate-700">Free</span>@endif
                </div>
                @if ($subscription)
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-muted">Plan</dt><dd class="font-bold">{{ $subscription->plan->name }}</dd></div>
                        <div><dt class="text-xs text-muted">Status</dt><dd><x-admin.status :value="$subscription->status" /></dd></div>
                        <div><dt class="text-xs text-muted">Provider</dt><dd class="font-bold">{{ ucfirst($subscription->provider) }}</dd></div>
                        <div><dt class="text-xs text-muted">Card</dt><dd class="font-bold">{{ $subscription->paymentMethodLabel() ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted">Started</dt><dd class="font-bold">{{ $subscription->started_at->timezone($tz)->format('j M Y') }}</dd></div>
                        <div><dt class="text-xs text-muted">Period ends</dt><dd class="font-bold">{{ $subscription->current_period_end->timezone($tz)->format('j M Y') }}</dd></div>
                        <div><dt class="text-xs text-muted">Next billing</dt><dd class="font-bold">{{ $subscription->next_billing_date?->timezone($tz)->format('j M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted">Pending plan</dt><dd class="font-bold">{{ $subscription->pendingPlan?->name ?? '—' }}</dd></div>
                    </dl>
                @else
                    <x-admin.empty title="No subscription" icon="repeat">This member uses the free tier.</x-admin.empty>
                @endif
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Recent payments</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Reference</th><th>Plan</th><th>Amount</th><th>Provider</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        @forelse ($payments as $p)
                            <tr>
                                <td class="font-mono text-xs">{{ $p->reference }}</td>
                                <td>{{ $p->plan?->name ?? '—' }} @if ($p->purpose === 'renewal')<span class="text-xs text-muted">(renewal)</span>@endif</td>
                                <td class="font-bold tabular-nums">{{ $p->formattedAmount() }}</td>
                                <td>{{ ucfirst($p->provider) }}</td>
                                <td><x-admin.status :value="$p->status" /></td>
                                <td class="whitespace-nowrap text-muted">{{ $p->created_at->timezone($tz)->format('j M Y, H:i') }}</td>
                            </tr>
                        @empty
                            <x-admin.empty title="No payments" icon="card" colspan="6" />
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Registered devices</h2><span class="text-xs text-muted">Push notifications</span></div>
                    <ul class="divide-y divide-line">
                        @forelse ($user->devices as $device)
                            <li class="flex items-center justify-between px-5 py-3 text-sm">
                                <span class="flex items-center gap-2"><x-admin.icon name="phone" class="size-4 text-muted" /><span class="font-bold">{{ $device->platform === 'ios' ? 'iOS' : ucfirst($device->platform) }}</span> <span class="text-muted">{{ $device->app_version ? 'v'.$device->app_version : '' }}</span></span>
                                <span class="text-xs text-muted">{{ $device->last_seen_at?->diffForHumans() ?? $device->created_at->diffForHumans() }}</span>
                            </li>
                        @empty
                            <li><x-admin.empty title="No devices" icon="phone" /></li>
                        @endforelse
                    </ul>
                </section>
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Active sessions</h2></div>
                    <ul class="divide-y divide-line">
                        @forelse ($sessions as $session)
                            <li class="px-5 py-3 text-sm">
                                <div class="font-bold">{{ $session->device_name ?: 'Mobile app' }}</div>
                                <div class="text-xs text-muted">{{ $session->platform ? strtoupper($session->platform) : '' }} · signed in {{ $session->created_at->diffForHumans() }} · {{ $session->ip }}</div>
                            </li>
                        @empty
                            <li><x-admin.empty title="No active sessions" icon="lock" /></li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Account history</h2></div>
                <ul class="divide-y divide-line">
                    @forelse ($history as $entry)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3 text-sm">
                            <span><span class="font-bold">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</span> @if ($entry->description)<span class="text-muted">— {{ $entry->description }}</span>@endif</span>
                            <span class="text-xs text-muted">{{ $entry->actor?->displayName() ?? ucfirst($entry->actor_type) }} · {{ $entry->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li><x-admin.empty title="No recorded history" icon="list" /></li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>

    <x-admin.modal show="showSuspend" title="Suspend member" size="max-w-lg">
        <p class="mb-4 text-sm text-muted">The member is signed out of every device immediately and cannot sign in until reactivated. Their subscription is not cancelled.</p>
        <x-admin.field label="Reason (recorded in the audit log)" for="reason" error="suspensionReason" required>
            <textarea id="reason" wire:model="suspensionReason" rows="3" class="input"></textarea>
        </x-admin.field>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-danger" wire:click="suspend">Suspend member</button>
        </x-slot:footer>
    </x-admin.modal>
</div>
