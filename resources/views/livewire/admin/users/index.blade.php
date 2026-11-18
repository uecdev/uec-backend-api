<div>
    <x-admin.page-header title="Members" description="Everyone who has created an account in the UEC app." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-line p-4 lg:flex-row lg:items-center">
            <x-admin.search placeholder="Search name, email, phone or ID" />
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:ml-auto lg:w-auto">
                <select wire:model.live="filterStatus" class="input" aria-label="Status">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                    <option value="deleted">Deleted accounts</option>
                </select>
                <select wire:model.live="filterPremium" class="input" aria-label="Subscription">
                    <option value="">Free & premium</option>
                    <option value="yes">Premium</option>
                    <option value="no">Free</option>
                </select>
                <select wire:model.live="filterPlatform" class="input" aria-label="Platform">
                    <option value="">All platforms</option>
                    <option value="android">Android</option>
                    <option value="ios">iOS</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="table">
                <thead>
                <tr>
                    <x-admin.sort-header field="name" label="Member" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th scope="col">Plan</th>
                    <th scope="col">Platform</th>
                    <x-admin.sort-header field="created_at" label="Joined" :sort-field="$sortField ?: 'created_at'" :sort-direction="$sortDirection" />
                    <x-admin.sort-header field="last_seen_at" label="Last seen" :sort-field="$sortField" :sort-direction="$sortDirection" />
                    <th scope="col">Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($users as $user)
                    <tr wire:key="u-{{ $user->id }}" class="cursor-pointer" onclick="if (!event.target.closest('a')) this.querySelector('a').click()">
                        <td>
                            <a href="{{ route('admin.users.show', $user->id) }}" wire:navigate class="flex items-center gap-3">
                                @if ($user->avatar_url)
                                    <img src="{{ $user->avatar_url }}" alt="" class="size-9 rounded-full object-cover" loading="lazy">
                                @else
                                    <span class="grid size-9 place-items-center rounded-full bg-brand-50 text-sm font-black text-brand-700">{{ strtoupper(mb_substr($user->displayName(), 0, 1)) }}</span>
                                @endif
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-ink">{{ $user->name ?: '—' }}</span>
                                    <span class="block truncate text-xs text-muted">{{ $user->email ?? $user->phone ?? 'Deleted account' }}</span>
                                </span>
                            </a>
                        </td>
                        <td>@if ($user->is_premium)<span class="badge bg-gold-50 text-gold-700">Premium</span>@else<span class="text-muted">Free</span>@endif</td>
                        <td class="text-muted">{{ $user->last_platform ? ucfirst($user->last_platform === 'ios' ? 'iOS' : $user->last_platform) : '—' }} @if ($user->last_app_version)<span class="text-xs">v{{ $user->last_app_version }}</span>@endif</td>
                        <td class="whitespace-nowrap text-muted">{{ $user->created_at->timezone(config('uec.timezone'))->format('j M Y') }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $user->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            @if ($user->trashed()) <x-admin.status value="deleted" />
                            @elseif ($user->suspended_at) <x-admin.status value="suspended" />
                            @else <x-admin.status value="active" /> @endif
                        </td>
                    </tr>
                @empty
                    <x-admin.empty title="No members found" icon="users" colspan="6">Try a different search or filter.</x-admin.empty>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())<div class="border-t border-line px-4 py-3">{{ $users->links() }}</div>@endif
    </div>
</div>
