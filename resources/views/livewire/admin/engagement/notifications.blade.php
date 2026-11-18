@php $tz = config('uec.timezone'); @endphp
<div>
    <x-admin.page-header title="Push notifications" description="Send announcements to members’ phones through Firebase Cloud Messaging. Tapping a notification opens the chosen screen in the app.">
        <x-slot:actions>@can('notifications.send')<button type="button" wire:click="create" class="btn-primary" @disabled(! $fcmReady)><x-admin.icon name="send" class="size-4" /> New notification</button>@endcan</x-slot:actions>
    </x-admin.page-header>

    @unless ($fcmReady)
        <div class="mb-5 rounded-xl border border-gold-100 bg-gold-50 px-4 py-3 text-sm text-gold-700"><strong>Firebase is not configured.</strong> Set FIREBASE_PROJECT_ID and the service-account credentials to enable push (see Integrations).</div>
    @endunless

    <div class="mb-4 inline-flex rounded-xl border border-line bg-cream p-1" role="tablist">
        <button type="button" role="tab" wire:click="$set('tab', 'campaigns')" @class(['tab', 'tab-active' => $tab === 'campaigns'])>Campaigns</button>
        <button type="button" role="tab" wire:click="$set('tab', 'log')" @class(['tab', 'tab-active' => $tab === 'log'])>Delivery log</button>
    </div>

    @if ($tab === 'campaigns')
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Notification</th><th>Audience</th><th>Status</th><th>Delivered</th><th>When</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($campaigns as $c)
                    <tr wire:key="c-{{ $c->id }}">
                        <td class="max-w-sm"><span class="block truncate font-bold">{{ $c->title }}</span><span class="block truncate text-xs text-muted">{{ $c->body }}</span><span class="text-xs text-muted">{{ $c->route }} · {{ $c->source === 'system' ? 'Automatic' : ($c->creator?->displayName() ?? '—') }}</span></td>
                        <td class="text-sm">
                            {{-- @switch is avoided: Livewire's morph markers produce invalid PHP between cases. --}}
                            @if ($c->audience === 'platform') {{ ($c->filters['platform'] ?? '') === 'ios' ? 'iOS' : 'Android' }} devices
                            @elseif ($c->audience === 'version') App v{{ $c->filters['app_version'] ?? '' }}
                            @elseif ($c->audience === 'users') {{ count($c->filters['user_ids'] ?? []) }} members
                            @else Everyone
                            @endif
                        </td>
                        <td><x-admin.status :value="$c->status" />@if ($c->error)<span class="mt-1 block max-w-48 truncate text-xs text-red-700" title="{{ $c->error }}">{{ $c->error }}</span>@endif</td>
                        <td class="text-sm tabular-nums">
                            @if ($c->success_count + $c->failure_count)
                                <span class="font-bold">{{ number_format($c->success_count) }}</span> / {{ number_format($c->target_count) }}
                                <span class="block text-xs text-muted">{{ $c->deliveryRate() }}% accepted @if ($c->invalid_token_count)· {{ $c->invalid_token_count }} stale tokens removed @endif</span>
                            @else
                                <span class="text-muted">~{{ number_format($c->target_count) }} devices</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-sm text-muted">{{ ($c->completed_at ?? $c->scheduled_at ?? $c->created_at)->timezone($tz)->format('j M, H:i') }}</td>
                        <td class="text-right">@can('notifications.send')@if ($c->status === \App\Enums\PushCampaignStatus::Scheduled)<button type="button" class="btn-ghost btn-sm text-red-700" wire:click="cancel('{{ $c->id }}')" wire:confirm="Cancel this scheduled notification?">Cancel</button>@endif @endcan</td>
                    </tr>
                @empty
                    <x-admin.empty title="No notifications sent yet" icon="bell" colspan="6" />
                @endforelse
                </tbody>
            </table>
            @if ($campaigns->hasPages())<div class="border-t border-line px-4 py-3">{{ $campaigns->links() }}</div>@endif
        </div>
        <p class="mt-3 text-xs text-muted">“Delivered” counts messages accepted by FCM for delivery; Firebase does not report whether a member opened the notification.</p>
    @else
        <div class="card">
            <div class="grid grid-cols-1 gap-3 border-b border-line p-4 sm:grid-cols-3">
                <select wire:model.live="logChannel" class="input" aria-label="Channel"><option value="">All channels</option><option value="mail">Email</option><option value="whatsapp">WhatsApp</option><option value="fcm">Push</option></select>
                <select wire:model.live="logStatus" class="input" aria-label="Status"><option value="">All statuses</option><option value="sent">Sent</option><option value="failed">Failed</option><option value="skipped">Skipped</option></select>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>When</th><th>Channel</th><th>Type</th><th>Recipient</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="nl-{{ $log->id }}">
                            <td class="whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($log->created_at)->timezone($tz)->format('j M, H:i:s') }}</td>
                            <td>{{ ['mail' => 'Email', 'whatsapp' => 'WhatsApp', 'fcm' => 'Push'][$log->channel] ?? $log->channel }}</td>
                            <td class="font-mono text-xs">{{ $log->type }}</td>
                            <td class="text-sm">{{ $log->destination ?? '—' }}</td>
                            <td><x-admin.status :value="$log->status" />@if ($log->error)<span class="block max-w-64 truncate text-xs text-red-700" title="{{ $log->error }}">{{ $log->error }}</span>@endif</td>
                        </tr>
                    @empty
                        <x-admin.empty title="No deliveries logged" icon="list" colspan="5" />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages())<div class="border-t border-line px-4 py-3">{{ $logs->links() }}</div>@endif
        </div>
    @endif

    <x-admin.modal show="showForm" title="New push notification">
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <div class="space-y-4 lg:col-span-3">
                <x-admin.field label="Title" for="ntitle" error="title" required help="Keep it under ~50 characters so it isn’t truncated."><input id="ntitle" type="text" wire:model.live.debounce.400ms="title" maxlength="120" class="input" placeholder="National Youth Convention"></x-admin.field>
                <x-admin.field label="Message" for="nbody" error="body" required><textarea id="nbody" wire:model.live.debounce.400ms="body" rows="3" maxlength="500" class="input"></textarea></x-admin.field>
                <x-admin.field label="Opens screen" for="nroute" error="route" help="In-app path. Examples: /events, /devotional, /hymns, /subscription">
                    <input id="nroute" type="text" wire:model="route" list="route-options" class="input font-mono text-sm">
                    <datalist id="route-options">@foreach ($routes as $r)<option value="{{ $r }}">@endforeach</datalist>
                </x-admin.field>
                <x-admin.field label="Audience" for="naud" error="audience">
                    <select id="naud" wire:model.live="audience" class="input">
                        <option value="all">Everyone with notifications on</option>
                        <option value="platform">One platform</option>
                        <option value="version">Members on a specific app version</option>
                        <option value="users">Specific members</option>
                    </select>
                </x-admin.field>
                @if ($audience === 'platform')
                    <select wire:model.live="platform" class="input" aria-label="Platform"><option value="android">Android</option><option value="ios">iOS</option></select>
                @elseif ($audience === 'version')
                    <x-admin.field label="App version" for="nver" error="appVersion"><input id="nver" type="text" wire:model.live.debounce.400ms="appVersion" class="input" placeholder="1.0.0"></x-admin.field>
                @elseif ($audience === 'users')
                    <x-admin.field label="Emails, phone numbers or member IDs" for="nusers" error="userEmails" help="Separate with commas or new lines."><textarea id="nusers" wire:model.live.debounce.600ms="userEmails" rows="3" class="input"></textarea></x-admin.field>
                @endif
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-2 font-bold"><input type="radio" value="now" wire:model.live="sendMode" class="text-brand-700"> Send now</label>
                    <label class="flex items-center gap-2 font-bold"><input type="radio" value="later" wire:model.live="sendMode" class="text-brand-700"> Schedule</label>
                </div>
                @if ($sendMode === 'later')
                    <x-admin.field label="Send at (Africa/Lagos)" for="nwhen" error="scheduledAt"><input id="nwhen" type="datetime-local" wire:model="scheduledAt" class="input"></x-admin.field>
                @endif
            </div>
            <div class="lg:col-span-2">
                <div class="text-xs font-bold text-muted uppercase">Preview</div>
                <div class="mt-2 rounded-2xl bg-brand-900 p-4">
                    <div class="rounded-xl bg-white/95 p-3 shadow">
                        <div class="flex items-center gap-2 text-[11px] text-muted"><img src="{{ asset('uec-logo.png') }}" alt="" class="size-4 rounded-full"> UEC · now</div>
                        <div class="mt-1 text-sm font-black text-ink">{{ $title ?: 'Notification title' }}</div>
                        <div class="text-sm text-muted">{{ $body ?: 'Your message appears here.' }}</div>
                    </div>
                </div>
                <div class="mt-4 rounded-xl bg-cream p-4 text-sm">
                    <div class="text-xs text-muted">Estimated reach</div>
                    <div class="text-2xl font-black tabular-nums">{{ number_format($this->estimate) }}</div>
                    <div class="text-xs text-muted">registered devices</div>
                </div>
            </div>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
            <button type="button" class="btn-primary" @click="$store.confirm.ask({ title: @js($sendMode === 'later' ? 'Schedule this notification?' : 'Send this notification now?'), message: 'It will go to about {{ number_format($this->estimate) }} devices. Push notifications cannot be recalled once sent.', confirmLabel: @js($sendMode === 'later' ? 'Schedule' : 'Send now'), danger: false, onConfirm: () => $wire.send() })">
                <x-admin.icon name="send" class="size-4" /> {{ $sendMode === 'later' ? 'Schedule' : 'Send' }}
            </button>
        </x-slot:footer>
    </x-admin.modal>
</div>
