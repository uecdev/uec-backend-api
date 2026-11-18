<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Admin\Concerns\AdminComponent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Services\Subscriptions\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    use AdminComponent;

    public string $userId;

    public bool $showSuspend = false;

    #[Validate('required|string|min:5|max:255')]
    public string $suspensionReason = '';

    public function mount(string $user): void
    {
        $this->authorizeAbility('users.view');
        $this->userId = User::withTrashed()->findOrFail($user)->id;
    }

    private function member(): User
    {
        return User::withTrashed()->findOrFail($this->userId);
    }

    public function suspend(TokenService $tokens): void
    {
        $this->authorizeAbility('users.manage');
        $this->validate();
        $user = $this->member();
        abort_if($user->id === auth()->id() || $user->hasRole('super_admin'), 403);

        $user->forceFill(['suspended_at' => now(), 'suspension_reason' => $this->suspensionReason])->save();
        $tokens->revokeAll($user, 'suspended');
        $this->audit('user.suspended', $user, $this->suspensionReason);

        $this->reset('showSuspend', 'suspensionReason');
        $this->toast('Member suspended and signed out of all devices.');
    }

    public function reactivate(): void
    {
        $this->authorizeAbility('users.manage');
        $user = $this->member();
        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        $this->audit('user.reactivated', $user);
        $this->toast('Member reactivated.');
    }

    public function signOutEverywhere(TokenService $tokens): void
    {
        $this->authorizeAbility('users.manage');
        $user = $this->member();
        $tokens->revokeAll($user, 'admin_revoked');
        $this->audit('user.sessions_revoked', $user);
        $this->toast('All sessions revoked. The member must sign in again.');
    }

    public function render(SubscriptionService $subscriptions)
    {
        $user = $this->member()->load(['devices' => fn ($q) => $q->latest('last_seen_at')]);

        return view('livewire.admin.users.show', [
            'user' => $user,
            'subscription' => $subscriptions->current($user),
            'entitlements' => $subscriptions->entitlements($user),
            'payments' => $user->payments()->with('plan')->latest()->limit(10)->get(),
            'sessions' => $user->refreshTokens()->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(),
            'activeDays30' => $user->activityDays()->where('date', '>=', now(config('uec.timezone'))->subDays(29)->toDateString())->count(),
            'recentActivity' => $user->activityDays()->latest('date')->limit(14)->get(),
            'readsCount' => $user->devotionalReads()->count(),
            'favouritesCount' => $user->favourites()->count(),
            'programsCount' => $user->programs()->count(),
            'history' => AuditLog::query()->with('actor')->where(fn ($q) => $q->where('subject_type', 'User')->where('subject_id', $user->id))->latest('created_at')->limit(15)->get(),
        ])->title($user->displayName());
    }
}
