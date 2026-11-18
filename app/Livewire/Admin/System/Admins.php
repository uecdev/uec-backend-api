<?php

namespace App\Livewire\Admin\System;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Support\Email;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

#[Layout('layouts.admin')]
#[Title('Admins & roles')]
class Admins extends Component
{
    use AdminComponent;

    #[Url]
    public string $tab = 'admins';

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'content_editor';

    public ?string $generatedPassword = null;

    /** @var array<string, list<string>> role => permissions (matrix tab) */
    public array $matrix = [];

    public function mount(): void
    {
        $this->authorizeAbility('system.admins');
        $this->loadMatrix();
    }

    private function loadMatrix(): void
    {
        $this->matrix = Role::query()->with('permissions')->whereIn('name', ['content_editor', 'finance', 'admin'])->get()
            ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->values()->all()])->all();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'email', 'generatedPassword');
        $this->role = 'content_editor';
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $this->resetValidation();
        $u = User::query()->findOrFail($id);
        $this->fill(['editingId' => $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email, 'role' => (string) $u->getRoleNames()->intersect(config('uec.admin.roles'))->first(), 'generatedPassword' => null, 'showForm' => true]);
    }

    public function save(): void
    {
        $this->authorizeAbility('system.admins');
        $this->email = (string) Email::normalize($this->email);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => ['required', Rule::in(config('uec.admin.roles'))],
        ]);

        if ($this->editingId === Auth::id() && $data['role'] !== 'super_admin' && Auth::user()->hasRole('super_admin')) {
            $this->addError('role', 'You cannot remove your own super administrator role.');

            return;
        }

        $user = $this->editingId ? User::query()->findOrFail($this->editingId) : User::query()->firstOrNew(['email' => $data['email']]);
        $isNew = ! $user->exists || ! $user->isAdmin();
        $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'email_verified_at' => $user->email_verified_at ?? now()]);

        if (! $user->exists) {
            // One-time password shown once to the inviting super admin; never stored in plain text or logged.
            $this->generatedPassword = Str::password(16);
            $user->password = $this->generatedPassword;
        }
        $user->save();
        $user->syncRoles(array_values(array_unique(array_merge(array_diff($user->getRoleNames()->all(), config('uec.admin.roles')), [$data['role']]))));

        $this->audit($isNew ? 'admin.granted' : 'admin.role_changed', $user, Permissions::ROLE_LABELS[$data['role']]);
        if (! $this->generatedPassword) {
            $this->showForm = false;
        }
        $this->toast($isNew ? 'Administrator added.' : 'Role updated.');
    }

    public function resetPassword(string $id, TokenService $tokens): void
    {
        $this->authorizeAbility('system.admins');
        $user = User::query()->findOrFail($id);
        $this->generatedPassword = Str::password(16);
        $user->forceFill(['password' => $this->generatedPassword])->save();
        $tokens->revokeAll($user, 'admin_password_reset');
        $this->editingId = $user->id;
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->role = (string) $user->getRoleNames()->intersect(config('uec.admin.roles'))->first();
        $this->showForm = true;
        $this->audit('admin.password_reset', $user);
    }

    public function revoke(string $id): void
    {
        $this->authorizeAbility('system.admins');
        $user = User::query()->findOrFail($id);
        abort_if($user->id === Auth::id(), 403, 'You cannot revoke your own access.');
        if ($user->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            $this->toast('At least one super administrator must remain.', 'error');

            return;
        }
        foreach (config('uec.admin.roles') as $role) {
            $user->removeRole($role);
        }
        $this->audit('admin.revoked', $user);
        $this->toast('Dashboard access removed.');
    }

    public function savePermissions(): void
    {
        $this->authorizeAbility('system.admins');
        $valid = array_keys(Permissions::ALL);
        foreach ($this->matrix as $roleName => $permissions) {
            $role = Role::findByName($roleName, 'web');
            $role->syncPermissions(array_values(array_intersect($permissions, $valid)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit('roles.permissions_updated', null, 'Role permission matrix updated', ['matrix' => $this->matrix]);
        $this->toast('Permissions updated.');
    }

    public function render()
    {
        return view('livewire.admin.system.admins', [
            'admins' => User::query()->role(config('uec.admin.roles'))->with('roles')->orderBy('name')->get(),
            'roleLabels' => array_intersect_key(Permissions::ROLE_LABELS, array_flip(config('uec.admin.roles'))),
            'permissions' => Permissions::ALL,
        ]);
    }
}
