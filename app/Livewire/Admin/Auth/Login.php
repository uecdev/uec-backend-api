<?php

namespace App\Livewire\Admin\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Email;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    #[Validate('required|email:rfc|max:191')]
    public string $email = '';

    #[Validate('required|string|max:128')]
    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit)
    {
        $this->validate();

        $key = 'admin-login:'.request()->ip().'|'.mb_strtolower($this->email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $email = Email::normalize($this->email);
        $user = User::query()->where('email', $email)->first();

        $valid = $user && Auth::guard('web')->validate(['email' => $email, 'password' => $this->password]);
        if (! $valid || ! $user->isAdmin() || $user->isSuspended()) {
            RateLimiter::hit($key, 300);
            if ($user) {
                $audit->security('admin_login_failed', 'Failed dashboard sign-in attempt.', ['email' => $email], $user);
            }
            throw ValidationException::withMessages(['email' => 'These credentials do not match an administrator account.']);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user, $this->remember);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('admin.login', $user, 'Signed in to the dashboard', [], $user);

        return $this->redirectIntended(route('admin.dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.auth.login');
    }
}
