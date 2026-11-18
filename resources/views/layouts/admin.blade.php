<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}UEC Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('uec-logo.png') }}">
    @vite(['resources/css/admin.css', 'resources/ts/admin.ts'])
    @livewireStyles
</head>
<body class="h-full" x-data="{ sidebar: false }" @keydown.escape.window="sidebar = false">
@php
    $user = auth()->user();
    $nav = [
        ['section' => null, 'items' => [
            ['route' => 'admin.dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard', 'can' => 'dashboard.view'],
        ]],
        ['section' => 'People', 'items' => [
            ['route' => 'admin.users.index', 'icon' => 'users', 'label' => 'Members', 'can' => 'users.view', 'match' => 'admin.users.*'],
            ['route' => 'admin.feedback', 'icon' => 'chat', 'label' => 'Feedback', 'can' => 'feedback.view'],
        ]],
        ['section' => 'Content', 'items' => [
            ['route' => 'admin.hymns.index', 'icon' => 'music', 'label' => 'Hymnal', 'can' => 'content.view', 'match' => 'admin.hymns.*'],
            ['route' => 'admin.devotionals.index', 'icon' => 'book', 'label' => 'Daily Delight', 'can' => 'content.view', 'match' => 'admin.devotionals.*'],
            ['route' => 'admin.sunday-school.index', 'icon' => 'school', 'label' => 'Sunday School', 'can' => 'content.view', 'match' => 'admin.sunday-school.*'],
            ['route' => 'admin.events', 'icon' => 'calendar', 'label' => 'Events', 'can' => 'content.view'],
            ['route' => 'admin.branches', 'icon' => 'map', 'label' => 'Branches', 'can' => 'content.view'],
            ['route' => 'admin.pages', 'icon' => 'file', 'label' => 'Pages', 'can' => 'content.view'],
            ['route' => 'admin.bible', 'icon' => 'bible', 'label' => 'Bible', 'can' => 'content.view'],
        ]],
        ['section' => 'Finance', 'items' => [
            ['route' => 'admin.payments', 'icon' => 'card', 'label' => 'Payments', 'can' => 'finance.view'],
            ['route' => 'admin.subscriptions', 'icon' => 'repeat', 'label' => 'Subscriptions', 'can' => 'finance.view'],
            ['route' => 'admin.plans', 'icon' => 'tag', 'label' => 'Plans', 'can' => 'finance.view'],
        ]],
        ['section' => 'Engagement', 'items' => [
            ['route' => 'admin.notifications', 'icon' => 'bell', 'label' => 'Push notifications', 'can' => 'notifications.view'],
            ['route' => 'admin.app', 'icon' => 'phone', 'label' => 'Mobile app', 'can' => 'app.manage'],
            ['route' => 'admin.reports', 'icon' => 'chart', 'label' => 'Reports', 'can' => 'reports.view'],
        ]],
        ['section' => 'System', 'items' => [
            ['route' => 'admin.admins', 'icon' => 'shield', 'label' => 'Admins & roles', 'can' => 'system.admins'],
            ['route' => 'admin.integrations', 'icon' => 'plug', 'label' => 'Integrations', 'can' => 'system.integrations'],
            ['route' => 'admin.operations', 'icon' => 'pulse', 'label' => 'Operations', 'can' => 'system.jobs'],
            ['route' => 'admin.audit', 'icon' => 'list', 'label' => 'Audit log', 'can' => 'system.audit'],
        ]],
    ];
@endphp

<div class="min-h-full">
    {{-- Mobile overlay --}}
    <div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-30 bg-brand-950/50 lg:hidden" @click="sidebar = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-68 -translate-x-full flex-col bg-brand-800 transition-transform duration-200 lg:translate-x-0"
           :class="sidebar && 'translate-x-0'" aria-label="Main navigation">
        <div class="flex items-center gap-3 px-5 pt-5 pb-4">
            <img src="{{ asset('uec-logo.png') }}" alt="" class="size-10 rounded-full bg-white p-0.5">
            <div class="leading-tight">
                <div class="text-[15px] font-black tracking-wide text-white">UEC Admin</div>
                <div class="text-xs text-brand-200/70">United Evangelical Church</div>
            </div>
            <button type="button" class="ml-auto rounded-lg p-1.5 text-brand-100 hover:bg-white/10 lg:hidden" @click="sidebar = false" aria-label="Close menu">
                <x-admin.icon name="x" />
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto px-3 pb-6">
            @foreach ($nav as $group)
                @php $items = array_filter($group['items'], fn ($i) => $user->can($i['can'])); @endphp
                @if ($items)
                    @if ($group['section'])<div class="nav-section">{{ $group['section'] }}</div>@endif
                    @foreach ($items as $item)
                        @php $active = request()->routeIs($item['match'] ?? $item['route']); @endphp
                        <a href="{{ route($item['route']) }}" wire:navigate @class(['nav-link', 'nav-link-active' => $active]) @if ($active) aria-current="page" @endif>
                            <x-admin.icon :name="$item['icon']" class="size-[18px] shrink-0" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                @endif
            @endforeach
            <div class="nav-section">Developers</div>
            <a href="{{ route('admin.api-docs') }}" target="_blank" rel="noopener" class="nav-link">
                <x-admin.icon name="globe" class="size-[18px] shrink-0" /><span>API reference</span>
            </a>
        </nav>
        <div class="border-t border-white/10 p-3">
            <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                <div class="grid size-9 shrink-0 place-items-center rounded-full bg-gold-500 text-sm font-black text-brand-900">
                    {{ strtoupper(mb_substr($user->displayName(), 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-sm font-bold text-white">{{ $user->displayName() }}</div>
                    <div class="truncate text-xs text-brand-200/70">{{ \App\Support\Permissions::ROLE_LABELS[$user->getRoleNames()->first()] ?? 'Administrator' }}</div>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-brand-100 hover:bg-white/10 hover:text-white" title="Sign out" aria-label="Sign out">
                        <x-admin.icon name="logout" class="size-[18px]" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="lg:pl-68">
        {{-- Top bar (mobile) --}}
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-cream/90 px-4 py-3 backdrop-blur lg:hidden">
            <button type="button" class="rounded-lg p-2 text-ink hover:bg-white" @click="sidebar = true" aria-label="Open menu">
                <x-admin.icon name="menu" />
            </button>
            <img src="{{ asset('uec-logo.png') }}" alt="" class="size-8 rounded-full">
            <span class="font-black text-brand-700">UEC Admin</span>
        </header>

        <main class="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            @if (config('app.env') !== 'production')
                <div class="mb-5 flex items-center gap-2 rounded-xl border border-gold-100 bg-gold-50 px-4 py-2.5 text-sm text-gold-700">
                    <x-admin.icon name="info" class="size-4" />
                    <span><strong>{{ ucfirst(config('app.env')) }} environment.</strong> Data and integrations may be test-only.</span>
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>

<x-admin.confirm-dialog />
<x-admin.toasts />

@if (session('toast'))
    {{-- Flashed toast; works for full page loads and wire:navigate visits. --}}
    <script>
        (() => {
            const fire = () => window.dispatchEvent(new CustomEvent('toast', { detail: @js(session('toast')) }));
            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => setTimeout(fire, 50)) : setTimeout(fire, 50);
        })();
    </script>
@endif
@livewireScriptConfig
</body>
</html>
