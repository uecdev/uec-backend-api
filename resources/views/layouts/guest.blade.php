<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Sign in' }} · UEC Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('uec-logo.png') }}">
    @vite(['resources/css/admin.css', 'resources/ts/admin.ts'])
    @livewireStyles
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-2">
    <div class="relative hidden overflow-hidden bg-brand-800 lg:block">
        <div class="absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle at 1px 1px, #fff 1px, transparent 0); background-size: 22px 22px;"></div>
        <div class="relative flex h-full flex-col justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <img src="{{ asset('uec-logo.png') }}" alt="" class="size-12 rounded-full bg-white p-0.5">
                <div>
                    <div class="text-lg font-black">United Evangelical Church</div>
                    <div class="text-sm text-brand-200">The Word is Truth</div>
                </div>
            </div>
            <div class="max-w-md">
                <h2 class="text-3xl leading-tight font-black">Administration for the UEC mobile app</h2>
                <p class="mt-4 text-brand-100/80">Manage the Daily Delight, the Sunday School Manual, the Hymnal, members, subscriptions and notifications from one place.</p>
            </div>
            <p class="text-xs text-brand-200/60">Authorised personnel only. All activity is logged.</p>
        </div>
    </div>
    <div class="flex items-center justify-center px-4 py-12 sm:px-8">
        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>
    </div>
</div>
<x-admin.toasts />
@livewireScriptConfig
</body>
</html>
