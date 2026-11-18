<div>
    <x-admin.page-header title="Administrators & roles" description="Who can access this dashboard and what each role may do. Super administrators always have every permission.">
        <x-slot:actions>
            @if ($tab === 'admins')<button type="button" wire:click="create" class="btn-primary"><x-admin.icon name="plus" class="size-4" /> Add administrator</button>
            @else<button type="button" wire:click="savePermissions" class="btn-primary"><x-admin.icon name="check" class="size-4" /> Save permissions</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 inline-flex rounded-xl border border-line bg-cream p-1" role="tablist">
        <button type="button" role="tab" wire:click="$set('tab', 'admins')" @class(['tab', 'tab-active' => $tab === 'admins'])>Administrators</button>
        <button type="button" role="tab" wire:click="$set('tab', 'roles')" @class(['tab', 'tab-active' => $tab === 'roles'])>Role permissions</button>
    </div>

    @if ($tab === 'admins')
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Administrator</th><th>Role</th><th>Last sign-in</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($admins as $a)
                    @php $r = $a->getRoleNames()->intersect(config('uec.admin.roles'))->first(); @endphp
                    <tr wire:key="a-{{ $a->id }}">
                        <td><span class="block font-bold">{{ $a->name }} @if ($a->id === auth()->id())<span class="text-xs font-normal text-muted">(you)</span>@endif</span><span class="block text-xs text-muted">{{ $a->email }}</span></td>
                        <td><span @class(['badge', 'bg-brand-700 text-white' => $r === 'super_admin', 'bg-brand-50 text-brand-700' => $r !== 'super_admin'])>{{ $roleLabels[$r] ?? $r }}</span></td>
                        <td class="text-muted">{{ $a->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>@if ($a->suspended_at)<x-admin.status value="suspended" />@else<x-admin.status value="active" />@endif</td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="btn-ghost btn-sm" wire:click="edit('{{ $a->id }}')">Change role</button>
                            <button type="button" class="btn-ghost btn-sm" @click="$store.confirm.ask({ title: 'Reset password?', message: 'A new one-time password is generated and all of their sessions end.', confirmLabel: 'Reset password', danger: false, onConfirm: () => $wire.resetPassword('{{ $a->id }}') })">Reset password</button>
                            @if ($a->id !== auth()->id())
                                <button type="button" class="btn-ghost btn-sm text-red-700" @click="$store.confirm.ask({ title: 'Remove dashboard access?', message: @js($a->email.' will no longer be able to sign in to the dashboard. Their member account is kept.'), confirmLabel: 'Remove access', onConfirm: () => $wire.revoke('{{ $a->id }}') })">Remove</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Permission</th>@foreach (['content_editor', 'finance', 'admin'] as $r)<th class="text-center">{{ $roleLabels[$r] }}</th>@endforeach<th class="text-center">{{ $roleLabels['super_admin'] }}</th></tr></thead>
                <tbody>
                @foreach ($permissions as $key => $label)
                    <tr wire:key="perm-{{ $key }}">
                        <td><span class="block font-mono text-xs font-bold">{{ $key }}</span><span class="block text-xs text-muted">{{ $label }}</span></td>
                        @foreach (['content_editor', 'finance', 'admin'] as $r)
                            <td class="text-center"><input type="checkbox" value="{{ $key }}" wire:model="matrix.{{ $r }}" class="size-4 rounded border-line text-brand-700" aria-label="{{ $roleLabels[$r] }}: {{ $key }}"></td>
                        @endforeach
                        <td class="text-center text-brand-700"><x-admin.icon name="check" class="inline size-4" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-admin.modal show="showForm" :title="$editingId ? 'Edit administrator' : 'Add administrator'" size="max-w-lg">
        @if ($generatedPassword)
            <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm" x-data="clipboard(@js($generatedPassword))">
                <p class="font-bold text-brand-800">One-time password for {{ $email }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 rounded-lg bg-white px-3 py-2 font-mono text-base select-all">{{ $generatedPassword }}</code>
                    <button type="button" class="btn-secondary btn-sm" @click="copy" x-text="copied ? 'Copied' : 'Copy'"></button>
                </div>
                <p class="mt-2 text-xs text-muted">Share it securely. It is not shown again or stored in readable form. Ask them to keep it private.</p>
            </div>
        @else
            <div class="space-y-4">
                <x-admin.field label="Name" for="aname" error="name" required><input id="aname" type="text" wire:model="name" class="input"></x-admin.field>
                <x-admin.field label="Email" for="aemail" error="email" required help="If a member account already uses this email, it is promoted."><input id="aemail" type="email" wire:model="email" class="input"></x-admin.field>
                <x-admin.field label="Role" for="arole" error="role" required>
                    <select id="arole" wire:model="role" class="input">@foreach ($roleLabels as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                </x-admin.field>
            </div>
        @endif
        <x-slot:footer>
            @if ($generatedPassword)
                <button type="button" class="btn-primary" wire:click="$set('showForm', false)">Done</button>
            @else
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button type="button" class="btn-primary" wire:click="save">Save</button>
            @endif
        </x-slot:footer>
    </x-admin.modal>
</div>
