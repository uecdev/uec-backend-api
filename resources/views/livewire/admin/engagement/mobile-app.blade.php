<div>
    <x-admin.page-header title="Mobile app" description="Remote configuration served to the Android and iOS apps at GET /api/v1/app/config, plus version adoption from registered devices.">
        <x-slot:actions><button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled"><x-admin.icon name="check" class="size-4" /> Save configuration</button></x-slot:actions>
    </x-admin.page-header>

    @if ($errors->any())<div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Versions & store listings</h2></div>
                <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                    <x-admin.field label="Latest version" for="latest" error="latest_version" required help="Newest build in the stores"><input id="latest" type="text" wire:model="latest_version" class="input" placeholder="1.2.0"></x-admin.field>
                    <x-admin.field label="Minimum supported version" for="min" error="min_supported_version" required help="Reserved for the app’s future force-update screen"><input id="min" type="text" wire:model="min_supported_version" class="input" placeholder="1.0.0"></x-admin.field>
                    <x-admin.field label="Google Play URL" for="play" error="store_url_android"><input id="play" type="url" wire:model="store_url_android" class="input" placeholder="https://play.google.com/store/apps/details?id=…"></x-admin.field>
                    <x-admin.field label="App Store URL" for="ios" error="store_url_ios"><input id="ios" type="url" wire:model="store_url_ios" class="input" placeholder="https://apps.apple.com/…"></x-admin.field>
                    <x-admin.field label="Update announcement" for="upd" error="update_message" class="sm:col-span-2" help="Optional banner text, e.g. “Version 1.2 adds hymn audio downloads.”"><input id="upd" type="text" wire:model="update_message" class="input"></x-admin.field>
                    <x-admin.field label="Maintenance notice" for="maint" error="maintenance_message" class="sm:col-span-2" help="Optional notice shown while services are degraded. Leave empty when everything is normal."><input id="maint" type="text" wire:model="maintenance_message" class="input"></x-admin.field>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Giving & support</h2></div>
                <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                    <x-admin.field label="Donation page" for="don" error="donation_url" help="Opened by the Give tab in an in-app browser (https only)"><input id="don" type="url" wire:model="donation_url" class="input" placeholder="https://uec.org.ng/give"></x-admin.field>
                    <x-admin.field label="Support email" for="sup" error="support_email"><input id="sup" type="email" wire:model="support_email" class="input"></x-admin.field>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <div><h2 class="card-title">Payment providers</h2><p class="text-xs text-muted">The first enabled provider is used for checkout in the app.</p></div>
                </div>
                <ul class="divide-y divide-line">
                    @foreach ($providers as $i => $p)
                        <li class="flex items-center gap-3 px-5 py-3" wire:key="prov-{{ $p }}">
                            <input type="checkbox" value="{{ $p }}" wire:model="enabledProviders" id="prov-{{ $p }}" class="size-4 rounded border-line text-brand-700" @disabled(! $configured[$p])>
                            <label for="prov-{{ $p }}" class="flex-1 font-bold">{{ ucfirst($p) }} @if ($i === 0)<span class="badge ml-1 bg-brand-50 text-brand-700">Primary</span>@endif</label>
                            @unless ($configured[$p])<span class="text-xs text-gold-700">Credentials missing</span>@endunless
                            <button type="button" class="btn-ghost btn-sm" wire:click="moveProvider({{ $i }}, -1)" @disabled($i === 0) aria-label="Move up"><x-admin.icon name="chevron-up" class="size-4" /></button>
                            <button type="button" class="btn-ghost btn-sm" wire:click="moveProvider({{ $i }}, 1)" @disabled($i === count($providers) - 1) aria-label="Move down"><x-admin.icon name="chevron-down" class="size-4" /></button>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="card">
                <div class="card-header"><div><h2 class="card-title">Automatic reminders</h2><p class="text-xs text-muted">Pending UEC confirmation of the push schedule (handoff §H.1). Times are Africa/Lagos.</p></div></div>
                <div class="divide-y divide-line">
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                        <label class="flex flex-1 items-start gap-3 text-sm"><input type="checkbox" wire:model="push_dde_daily_enabled" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Daily Delight reminder</strong><br><span class="text-muted">Every day, with today’s title, opening today’s reading.</span></span></label>
                        <input type="time" wire:model="push_dde_daily_time" class="input w-32" aria-label="Daily reminder time">
                    </div>
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                        <label class="flex flex-1 items-start gap-3 text-sm"><input type="checkbox" wire:model="push_ssm_reminder_enabled" class="mt-0.5 size-4 rounded border-line text-brand-700"><span><strong>Sunday School reminder</strong><br><span class="text-muted">Saturdays, with tomorrow’s lesson plan description.</span></span></label>
                        <input type="time" wire:model="push_ssm_reminder_time" class="input w-32" aria-label="Sunday School reminder time">
                    </div>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Version adoption</h2></div>
                <dl class="grid grid-cols-2 gap-px bg-line text-sm">
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">On latest</dt><dd class="text-2xl font-black">{{ $latestPct !== null ? $latestPct.'%' : '—' }}</dd></div>
                    <div class="bg-white px-5 py-4"><dt class="text-xs text-muted">Supported</dt><dd class="text-2xl font-black">{{ $supportedPct !== null ? $supportedPct.'%' : '—' }}</dd></div>
                </dl>
                <ul class="divide-y divide-line text-sm">
                    @forelse ($versions as $v)
                        <li class="flex items-center justify-between px-5 py-2.5">
                            <span><span class="font-bold">{{ $v->platform === 'ios' ? 'iOS' : ucfirst($v->platform) }}</span> <span class="text-muted">v{{ $v->app_version ?? '?' }}</span>
                                @if ($v->app_version && $min_supported_version && version_compare($v->app_version, $min_supported_version, '<'))<span class="badge ml-1 bg-red-50 text-red-700">Unsupported</span>@endif</span>
                            <span class="tabular-nums">{{ number_format($v->total) }} <span class="text-xs text-muted">({{ $deviceTotal ? round($v->total / $deviceTotal * 100) : 0 }}%)</span></span>
                        </li>
                    @empty
                        <li><x-admin.empty title="No devices registered" icon="phone">Devices appear after members sign in and allow notifications.</x-admin.empty></li>
                    @endforelse
                </ul>
                <p class="border-t border-line px-5 py-3 text-xs text-muted">Based on {{ number_format($deviceTotal) }} push-registered devices. Store download and install counts are not available without Play Console / App Store Connect analytics.</p>
            </section>
        </div>
    </div>
</div>
