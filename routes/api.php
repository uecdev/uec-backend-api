<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BibleController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DevotionalController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\HymnController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SundaySchoolController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| UEC REST API v1 — mounted at /api/v1 (see bootstrap/app.php)
|--------------------------------------------------------------------------
| Endpoint numbers (#n) refer to docs/backend-handoff.md §C.3.
*/

Route::middleware('throttle:api')->group(function () {

    // ---- Authentication (#1–#10) -------------------------------------------
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:otp')->name('register.verify');
        Route::post('otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:otp')->name('otp.resend');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('google', [AuthController::class, 'google'])->name('google');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/verify', [AuthController::class, 'verifyPasswordReset'])->middleware('throttle:otp')->name('password.verify');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
    });

    // ---- Public content (#19–#39, #45) ------------------------------------
    Route::get('app/config', AppConfigController::class)->name('app.config');
    Route::get('openapi.yaml', [OpenApiController::class, 'spec'])->name('openapi');
    Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');

    Route::get('hymns', [HymnController::class, 'index'])->name('hymns.index');
    Route::get('hymns/{id}', [HymnController::class, 'show'])->name('hymns.show');

    Route::get('devotionals/editions', [DevotionalController::class, 'editions'])->name('devotionals.editions');
    Route::get('devotionals/editions/{year}/entries', [DevotionalController::class, 'entries'])->whereNumber('year')->name('devotionals.entries');
    Route::get('devotionals/entries/{date}', [DevotionalController::class, 'entry'])->name('devotionals.entry');

    Route::get('sunday-school/manuals', [SundaySchoolController::class, 'manuals'])->name('ss.manuals');
    Route::get('sunday-school/manuals/{year}', [SundaySchoolController::class, 'manual'])->whereNumber('year')->name('ss.manual');
    Route::get('sunday-school/lessons/{id}', [SundaySchoolController::class, 'lesson'])->name('ss.lesson');
    Route::get('sunday-school/search', [SundaySchoolController::class, 'search'])->name('ss.search');

    Route::get('bible/versions', [BibleController::class, 'versions'])->name('bible.versions');
    Route::get('bible/{version}/chapters/{book}/{chapter}', [BibleController::class, 'chapter'])->whereNumber('chapter')->name('bible.chapter');

    Route::get('events', [DirectoryController::class, 'events'])->name('events.index');
    Route::get('events/{id}', [DirectoryController::class, 'event'])->name('events.show');
    Route::get('branches', [DirectoryController::class, 'branches'])->name('branches.index');
    Route::get('search', [DirectoryController::class, 'search'])->name('search');

    Route::get('subscription/plans', [SubscriptionController::class, 'plans'])->name('subscription.plans');

    // #52 Feedback — guests allowed; a bearer token, if present, links the user.
    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:uploads')->name('feedback.store');

    // ---- Authenticated member endpoints -----------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy');
        Route::post('me/avatar', [MeController::class, 'avatar'])->middleware('throttle:uploads')->name('me.avatar');
        Route::post('me/contact', [MeController::class, 'contact'])->middleware('throttle:auth')->name('me.contact');
        Route::post('me/contact/verify', [MeController::class, 'verifyContact'])->middleware('throttle:otp')->name('me.contact.verify');

        Route::post('me/devices', [DeviceController::class, 'store'])->name('me.devices.store');
        Route::delete('me/devices/{token}', [DeviceController::class, 'destroy'])->where('token', '.*')->name('me.devices.destroy');

        Route::get('devotionals/editions/{year}/download', [DevotionalController::class, 'download'])->whereNumber('year')->name('devotionals.download');
        Route::get('sunday-school/manuals/{year}/download', [SundaySchoolController::class, 'download'])->whereNumber('year')->name('ss.download');

        Route::get('me/devotional-progress', [DevotionalController::class, 'progress'])->name('me.progress');
        Route::put('me/devotional-progress/{date}', [DevotionalController::class, 'markRead'])->name('me.progress.mark');

        Route::get('me/favourites', [SyncController::class, 'favourites'])->name('me.favourites');
        Route::put('me/favourites', [SyncController::class, 'replaceFavourites'])->name('me.favourites.replace');
        Route::get('me/programs', [SyncController::class, 'programs'])->name('me.programs');
        Route::put('me/programs/{id}', [SyncController::class, 'upsertProgram'])->name('me.programs.upsert');
        Route::delete('me/programs/{id}', [SyncController::class, 'deleteProgram'])->name('me.programs.destroy');

        Route::get('me/subscription', [SubscriptionController::class, 'show'])->name('me.subscription');
        Route::post('me/subscription/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:payments')->name('me.subscription.checkout');
        Route::post('me/subscription/verify', [SubscriptionController::class, 'verify'])->name('me.subscription.verify');
        Route::get('me/subscription/change-preview', [SubscriptionController::class, 'changePreview'])->name('me.subscription.preview');
        Route::post('me/subscription/change', [SubscriptionController::class, 'change'])->middleware('throttle:payments')->name('me.subscription.change');
        Route::post('me/subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('throttle:payments')->name('me.subscription.cancel');
    });
});

// ---- Gateways (W1–W3): signature-verified, separately throttled ---------------
Route::middleware('throttle:webhooks')->group(function () {
    Route::post('webhooks/paystack', [PaymentWebhookController::class, 'paystack'])->name('webhooks.paystack');
    Route::post('webhooks/monnify', [PaymentWebhookController::class, 'monnify'])->name('webhooks.monnify');
    Route::get('payments/callback', [PaymentWebhookController::class, 'callback'])->name('payments.callback');
});
