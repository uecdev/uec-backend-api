<?php

use App\Services\Notifications\PushCampaignService;
use App\Services\Notifications\ScheduledReminders;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler — run `php artisan schedule:run` every minute (cron) or
| `php artisan schedule:work` under a supervisor. Jobs that must not
| overlap across servers use onOneServer() (requires the Redis cache store).
|--------------------------------------------------------------------------
*/

Artisan::command('uec:subscriptions:lifecycle', function (SubscriptionService $subscriptions) {
    $result = $subscriptions->enforceLifecycle();
    $this->info("Expired {$result['expired']}, marked past due {$result['past_due']}.");
})->purpose('Expire ended/past-due subscriptions and flag overdue renewals');

Artisan::command('uec:subscriptions:renew', function (PaymentService $payments) {
    $this->info('Renewal charges attempted: '.$payments->chargeDueRenewals());
})->purpose('Charge due renewals for gateways we drive (Monnify, Paystack without a plan)');

Artisan::command('uec:push:dispatch', function (PushCampaignService $push, ScheduledReminders $reminders) {
    $due = $push->dispatchDue();
    $system = count($reminders->run());
    $this->info("Dispatched {$due} scheduled campaign(s) and {$system} reminder(s).");
})->purpose('Send scheduled push campaigns and configured daily reminders');

// Heartbeat read by the Operations health page to prove cron is running schedule:run.
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->toIso8601String(), 600))->everyMinute()->name('scheduler-heartbeat')->onOneServer();
Schedule::command('uec:push:dispatch')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('uec:subscriptions:renew')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('uec:subscriptions:lifecycle')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('uec:prune')->dailyAt('02:30')->timezone('Africa/Lagos')->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('queue:prune-batches --hours=168')->daily()->onOneServer();
