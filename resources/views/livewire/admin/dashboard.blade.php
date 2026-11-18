@php
    $naira = fn (int $kobo) => '₦'.number_format($kobo / 100, 0);
    $num = fn ($n) => number_format((int) $n);
    $platforms = ['android' => 'Android', 'ios' => 'iOS', 'web' => 'Web'];
    $activePlatforms = $m['devices']['active_by_platform'];
    $s = $m['series'];
@endphp
<div>
    <x-admin.page-header title="Dashboard" description="Live measurements from the UEC API. Figures marked “Estimate” are derived; app-store install counts are not available without a store analytics integration.">
        <x-slot:actions>
            <div class="flex rounded-xl border border-line bg-cream p-1" role="group" aria-label="Date range">
                @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'] as $value => $label)
                    <button type="button" wire:click="$set('days', {{ $value }})" @class(['tab', 'tab-active' => $days === $value]) aria-pressed="{{ $days === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <button type="button" wire:click="refresh" class="btn-secondary" title="Recalculate now"><x-admin.icon name="refresh" class="size-4" /></button>
            @can('reports.export')
                <button type="button" wire:click="export" class="btn-secondary"><x-admin.icon name="download" class="size-4" /> Export</button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div wire:loading.delay.class="opacity-60" class="space-y-6 transition-opacity">
        {{-- KPI row --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat label="Total members" :value="$num($m['users']['total'])" icon="users" :hint="$num($m['users']['new']).' new in range'" :trend="$m['users']['new_trend']" />
            <x-admin.stat label="Monthly active users" :value="$num($m['users']['mau'])" icon="pulse" :hint="'DAU '.$num($m['users']['dau']).' · WAU '.$num($m['users']['wau'])" />
            <x-admin.stat label="Revenue" :value="$naira($m['revenue']['total'])" icon="card" hint="Successful payments in range" :trend="$m['revenue']['trend']" />
            <x-admin.stat label="Premium members" :value="$num($m['subscriptions']['premium_users'])" icon="star" :hint="'MRR '.$naira($m['subscriptions']['mrr_estimate'])" source="MRR is an estimate" />
        </div>

        {{-- Activity + registrations --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <section class="card xl:col-span-2" aria-labelledby="activity-title">
                <div class="card-header">
                    <div>
                        <h2 id="activity-title" class="card-title">Daily active members & registrations</h2>
                        <p class="text-xs text-muted">Signed-in members who used the app each day (Africa/Lagos)</p>
                    </div>
                </div>
                <div class="h-72 p-4" wire:key="activity-{{ $days }}" x-data="chart(@js(['type' => 'line', 'labels' => $s['labels'], 'datasets' => [['label' => 'Active members', 'data' => $s['active_users']], ['label' => 'New registrations', 'data' => $s['registrations']]]]))">
                    <canvas x-ref="canvas" role="img" aria-label="Line chart of daily active members and registrations"></canvas>
                </div>
            </section>

            <section class="card" aria-labelledby="platform-title">
                <div class="card-header">
                    <div>
                        <h2 id="platform-title" class="card-title">Platform split</h2>
                        <p class="text-xs text-muted">Distinct active members in range</p>
                    </div>
                </div>
                @if (array_sum($activePlatforms) > 0)
                    <div class="h-56 p-4" wire:key="platform-{{ $days }}" x-data="chart(@js(['type' => 'doughnut', 'labels' => array_map(fn ($p) => $platforms[$p] ?? $p, array_keys($activePlatforms)), 'datasets' => [['label' => 'Members', 'data' => array_values($activePlatforms)]]]))">
                        <canvas x-ref="canvas" role="img" aria-label="Doughnut chart of members by platform"></canvas>
                    </div>
                @else
                    <x-admin.empty title="No activity yet" icon="phone">Platform data appears once members use the app.</x-admin.empty>
                @endif
                <dl class="grid grid-cols-3 border-t border-line text-center text-sm">
                    @foreach ($platforms as $key => $label)
                        <div class="px-2 py-3">
                            <dt class="text-xs text-muted">{{ $label }} devices</dt>
                            <dd class="font-black tabular-nums">{{ $num($m['devices']['by_platform'][$key] ?? 0) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>

        {{-- Revenue + providers --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <section class="card xl:col-span-2" aria-labelledby="revenue-title">
                <div class="card-header">
                    <h2 id="revenue-title" class="card-title">Revenue per day</h2>
                    @can('finance.view')<a href="{{ route('admin.payments') }}" wire:navigate class="text-sm font-bold text-brand-700 hover:underline">View payments</a>@endcan
                </div>
                <div class="h-64 p-4" wire:key="revenue-{{ $days }}" x-data="chart(@js(['type' => 'bar', 'money' => true, 'labels' => $s['labels'], 'datasets' => [['label' => 'Revenue', 'data' => $s['revenue']]]]))">
                    <canvas x-ref="canvas" role="img" aria-label="Bar chart of daily revenue"></canvas>
                </div>
            </section>

            <section class="card" aria-labelledby="providers-title">
                <div class="card-header"><h2 id="providers-title" class="card-title">Payment providers</h2></div>
                <div class="divide-y divide-line">
                    @forelse ($m['revenue']['providers'] as $p)
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between">
                                <span class="font-black">{{ ucfirst($p['provider']) }}</span>
                                <span class="text-sm font-bold tabular-nums">{{ $naira($p['revenue']) }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-cream" role="img" aria-label="Success rate {{ $p['success_rate'] ?? 0 }}%">
                                <div class="h-full rounded-full bg-brand-600" style="width: {{ $p['success_rate'] ?? 0 }}%"></div>
                            </div>
                            <div class="mt-1.5 flex justify-between text-xs text-muted">
                                <span>{{ $p['success_rate'] ?? 0 }}% success</span>
                                <span>{{ $p['success'] }} ok · {{ $p['failed'] }} failed · {{ $p['abandoned'] }} abandoned</span>
                            </div>
                        </div>
                    @empty
                        <x-admin.empty title="No payments in range" icon="card" />
                    @endforelse
                </div>
            </section>
        </div>

        {{-- Subscriptions, engagement, notifications --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section class="card" aria-labelledby="subs-title">
                <div class="card-header"><h2 id="subs-title" class="card-title">Subscriptions</h2></div>
                <dl class="grid grid-cols-2 gap-px bg-line text-sm">
                    @foreach ([['Auto-renewing', $m['subscriptions']['auto_renewing']], ['Cancelled, still active', $m['subscriptions']['cancelled_with_access']], ['Past due (grace)', $m['subscriptions']['past_due']], ['Churned (30 days)', $m['subscriptions']['churned_30d']]] as [$label, $value])
                        <div class="bg-white px-5 py-4">
                            <dt class="text-xs text-muted">{{ $label }}</dt>
                            <dd class="mt-1 text-xl font-black tabular-nums">{{ $num($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="px-5 py-4">
                    <div class="mb-2 text-xs font-bold text-muted uppercase">By plan</div>
                    @forelse ($m['subscriptions']['by_plan'] as $plan => $count)
                        <div class="flex justify-between py-1 text-sm"><span>{{ $plan }}</span><span class="font-bold tabular-nums">{{ $num($count) }}</span></div>
                    @empty
                        <p class="text-sm text-muted">No premium members yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="card" aria-labelledby="engagement-title">
                <div class="card-header">
                    <div>
                        <h2 id="engagement-title" class="card-title">Content engagement</h2>
                        <p class="text-xs text-muted">Synced from signed-in members</p>
                    </div>
                </div>
                <div class="h-36 px-4 pt-3" wire:key="reads-{{ $days }}" x-data="chart(@js(['type' => 'line', 'labels' => $s['labels'], 'datasets' => [['label' => 'Devotional reads', 'data' => $s['devotional_reads']]]]))">
                    <canvas x-ref="canvas" role="img" aria-label="Line chart of devotional reads"></canvas>
                </div>
                <dl class="grid grid-cols-3 border-t border-line text-center text-sm">
                    <div class="px-2 py-3"><dt class="text-xs text-muted">Reads</dt><dd class="font-black tabular-nums">{{ $num($m['engagement']['devotional_reads']) }}</dd></div>
                    <div class="px-2 py-3"><dt class="text-xs text-muted">Readers</dt><dd class="font-black tabular-nums">{{ $num($m['engagement']['readers']) }}</dd></div>
                    <div class="px-2 py-3"><dt class="text-xs text-muted">Programs</dt><dd class="font-black tabular-nums">{{ $num($m['engagement']['programs']) }}</dd></div>
                </dl>
                <div class="border-t border-line px-5 py-4">
                    <div class="mb-2 text-xs font-bold text-muted uppercase">Most-favourited hymns</div>
                    @forelse ($m['engagement']['top_hymns'] as $h)
                        <div class="flex justify-between gap-3 py-1 text-sm"><span class="truncate"><span class="text-muted">#{{ $h['number'] }}</span> {{ $h['title'] }}</span><span class="font-bold tabular-nums">{{ $h['total'] }}</span></div>
                    @empty
                        <p class="text-sm text-muted">No favourites synced yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="card" aria-labelledby="notif-title">
                <div class="card-header"><h2 id="notif-title" class="card-title">Notifications & retention</h2></div>
                @php $pushTotal = $m['notifications']['push_success'] + $m['notifications']['push_failure']; @endphp
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between px-5 py-3"><dt class="text-muted">Push campaigns in range</dt><dd class="font-black tabular-nums">{{ $num($m['notifications']['campaigns']) }}</dd></div>
                    <div class="flex justify-between px-5 py-3"><dt class="text-muted">Push delivery rate</dt><dd class="font-black tabular-nums">{{ $pushTotal ? round($m['notifications']['push_success'] / $pushTotal * 100, 1).'%' : '—' }}</dd></div>
                    @foreach (['mail' => 'Emails', 'whatsapp' => 'WhatsApp messages', 'fcm' => 'Direct pushes'] as $channel => $label)
                        @php $c = $m['notifications']['by_channel'][$channel] ?? []; @endphp
                        <div class="flex justify-between px-5 py-3"><dt class="text-muted">{{ $label }} sent / failed</dt><dd class="font-black tabular-nums">{{ $num($c['sent'] ?? 0) }} / <span class="text-red-700">{{ $num($c['failed'] ?? 0) }}</span></dd></div>
                    @endforeach
                    <div class="flex justify-between px-5 py-3"><dt class="text-muted">Day-7 retention</dt><dd class="font-black tabular-nums">{{ $m['users']['retention_d7'] !== null ? $m['users']['retention_d7'].'%' : '—' }}</dd></div>
                    <div class="flex justify-between px-5 py-3"><dt class="text-muted">Day-30 retention</dt><dd class="font-black tabular-nums">{{ $m['users']['retention_d30'] !== null ? $m['users']['retention_d30'].'%' : '—' }}</dd></div>
                    <div class="flex justify-between px-5 py-3"><dt class="text-muted">Open feedback</dt><dd class="font-black tabular-nums">{{ $num($m['feedback_open']) }}</dd></div>
                </dl>
            </section>
        </div>

        {{-- Versions --}}
        <section class="card" aria-labelledby="versions-title">
            <div class="card-header">
                <div>
                    <h2 id="versions-title" class="card-title">App version distribution</h2>
                    <p class="text-xs text-muted">Registered devices by reported app version</p>
                </div>
                @can('app.manage')<a href="{{ route('admin.app') }}" wire:navigate class="text-sm font-bold text-brand-700 hover:underline">Manage versions</a>@endcan
            </div>
            @if ($m['devices']['by_version'])
                <div class="h-56 p-4" wire:key="versions-{{ $days }}" x-data="chart(@js(['type' => 'bar', 'labels' => array_map('strval', array_keys($m['devices']['by_version'])), 'datasets' => [['label' => 'Devices', 'data' => array_values($m['devices']['by_version'])]]]))">
                    <canvas x-ref="canvas" role="img" aria-label="Bar chart of devices by app version"></canvas>
                </div>
            @else
                <x-admin.empty title="No devices registered yet" icon="phone">Devices register for push notifications after members sign in.</x-admin.empty>
            @endif
        </section>
    </div>
</div>
