<div>
    <x-admin.page-header title="Feedback & suggestions" description="Submitted from the app’s Feedback screen, by guests and members. Contact only members who consented." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 lg:flex-row lg:items-center">
            <x-admin.search placeholder="Search messages" />
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:ml-auto">
                <select wire:model.live="filterStatus" class="input" aria-label="Status">
                    <option value="open">Open (new & in review)</option>
                    @foreach (\App\Models\Feedback::STATUSES as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
                    <option value="all">All</option>
                </select>
                <select wire:model.live="filterType" class="input" aria-label="Type">
                    <option value="">All types</option>
                    @foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace(['_', '-'], ' ', $t)) }}</option>@endforeach
                </select>
            </div>
        </div>
        <ul class="divide-y divide-line" wire:loading.class="opacity-60">
            @forelse ($items as $f)
                <li wire:key="f-{{ $f->id }}">
                    <button type="button" wire:click="open('{{ $f->id }}')" class="flex w-full flex-col gap-1 px-5 py-4 text-left hover:bg-cream/50 sm:flex-row sm:items-start sm:gap-4">
                        <div class="flex shrink-0 items-center gap-2 sm:w-40 sm:flex-col sm:items-start">
                            <x-admin.status :value="$f->status" />
                            <span class="badge bg-cream text-muted">{{ ucfirst(str_replace(['_', '-'], ' ', $f->type)) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="line-clamp-2 text-sm text-ink">{{ $f->message }}</p>
                            <p class="mt-1 text-xs text-muted">
                                {{ $f->user?->displayName() ?? 'Guest' }} · {{ $f->created_at->diffForHumans() }}
                                @if ($f->platform) · {{ $f->platform === 'ios' ? 'iOS' : ucfirst($f->platform) }} {{ $f->app_version }}@endif
                                @if ($f->attachments->isNotEmpty()) · {{ $f->attachments->count() }} attachment(s)@endif
                                @if ($f->contact_consent) · <span class="font-bold text-brand-700">Happy to be contacted</span>@endif
                            </p>
                        </div>
                    </button>
                </li>
            @empty
                <li><x-admin.empty title="No feedback here" icon="chat">New submissions from the app will appear in this inbox.</x-admin.empty></li>
            @endforelse
        </ul>
        @if ($items->hasPages())<div class="border-t border-line px-4 py-3">{{ $items->links() }}</div>@endif
    </div>

    <x-admin.modal show="showDetail" title="Feedback">
        @if ($selected)
            <div class="space-y-5">
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="badge bg-cream text-muted">{{ ucfirst(str_replace(['_', '-'], ' ', $selected->type)) }}</span>
                    <span class="badge bg-cream text-muted">{{ $selected->created_at->timezone(config('uec.timezone'))->format('j M Y, H:i') }}</span>
                    @if ($selected->platform)<span class="badge bg-cream text-muted">{{ strtoupper($selected->platform) }} {{ $selected->app_version }}</span>@endif
                </div>
                <p class="whitespace-pre-line rounded-xl bg-cream/60 p-4 text-sm">{{ $selected->message }}</p>
                <div class="text-sm">
                    <div class="font-bold">From</div>
                    @if ($selected->user)
                        <a href="{{ route('admin.users.show', $selected->user->id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $selected->user->displayName() }}</a>
                        @if ($selected->contact_consent)
                            <span class="text-muted">— consented to contact: {{ $selected->user->email ?? $selected->user->phone }}</span>
                        @else
                            <span class="text-muted">— did not consent to be contacted</span>
                        @endif
                    @else
                        <span class="text-muted">Guest (no account)</span>
                    @endif
                </div>
                @if ($selected->attachments->isNotEmpty())
                    <div>
                        <div class="mb-2 text-sm font-bold">Attachments</div>
                        <ul class="space-y-2">
                            @foreach ($selected->attachments as $a)
                                <li><a href="{{ route('admin.files.feedback', $a->id) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm font-bold hover:bg-cream"><x-admin.icon name="file" class="size-4" /> {{ $a->original_name ?: 'Attachment' }} <span class="font-normal text-muted">{{ number_format($a->size / 1024) }} KB</span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @can('feedback.manage')
                    <div class="grid grid-cols-1 gap-4 border-t border-line pt-5">
                        <x-admin.field label="Status" for="fstatus" error="status">
                            <select id="fstatus" wire:model="status" class="input">@foreach (\App\Models\Feedback::STATUSES as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach</select>
                        </x-admin.field>
                        <x-admin.field label="Internal notes" for="fnotes" error="notes"><textarea id="fnotes" wire:model="notes" rows="3" class="input"></textarea></x-admin.field>
                    </div>
                @endcan
                @if ($selected->handler)<p class="text-xs text-muted">Last handled by {{ $selected->handler->displayName() }}.</p>@endif
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="open = false">Close</button>
            @can('feedback.manage')<button type="button" class="btn-primary" wire:click="saveTriage">Save</button>@endcan
        </x-slot:footer>
    </x-admin.modal>
</div>
