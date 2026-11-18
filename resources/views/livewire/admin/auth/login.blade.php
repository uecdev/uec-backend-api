<div>
    <div class="mb-8 lg:hidden">
        <img src="{{ asset('uec-logo.png') }}" alt="United Evangelical Church" class="size-14 rounded-full">
    </div>
    <h1 class="text-2xl font-black tracking-tight">Welcome back</h1>
    <p class="mt-1 text-sm text-muted">Sign in to the UEC administration dashboard.</p>

    @if (session('error'))
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800" role="alert">{{ session('error') }}</div>
    @endif

    <form wire:submit="login" class="mt-8 space-y-5" novalidate>
        <x-admin.field label="Email address" for="email" error="email">
            <input id="email" type="email" wire:model="email" autocomplete="username" autofocus
                   @class(['input', 'input-error' => $errors->has('email')]) aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
        </x-admin.field>
        <x-admin.field label="Password" for="password" error="password">
            <div x-data="{ show: false }" class="relative">
                <input id="password" :type="show ? 'text' : 'password'" wire:model="password" autocomplete="current-password"
                       @class(['input pr-11', 'input-error' => $errors->has('password')])>
                <button type="button" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-lg p-1.5 text-muted hover:text-ink"
                        @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-admin.icon name="eye" class="size-4" />
                </button>
            </div>
        </x-admin.field>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-line text-brand-700 focus:ring-brand-500">
            Keep me signed in on this device
        </label>
        <button type="submit" class="btn-primary w-full py-3" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
    <p class="mt-8 text-xs text-muted">Forgotten your password? Ask a super administrator to reset it.</p>
</div>
