<?php

use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\OpenApiController;
use App\Livewire\Admin;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administration dashboard — https://app.<domain> (UEC_SURFACE=admin|all)
|--------------------------------------------------------------------------
| Session + CSRF protected (web middleware). Every page additionally checks
| its granular permission inside the Livewire component.
*/

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Admin\Auth\Login::class)->name('admin.login');
});

Route::post('/logout', function (Request $request) {
    app(AuditLogger::class)->log('admin.logout', $request->user(), 'Signed out');
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('admin.login');
})->middleware('auth')->name('admin.logout');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::livewire('/', Admin\Dashboard::class)->name('admin.dashboard');

    // People
    Route::livewire('/members', Admin\Users\Index::class)->name('admin.users.index');
    Route::livewire('/members/{user}', Admin\Users\Show::class)->name('admin.users.show');
    Route::livewire('/feedback', Admin\Feedback\Index::class)->name('admin.feedback');

    // Content
    Route::livewire('/hymns', Admin\Content\Hymns\Index::class)->name('admin.hymns.index');
    Route::livewire('/hymns/new', Admin\Content\Hymns\Form::class)->name('admin.hymns.create');
    Route::livewire('/hymns/{hymn}', Admin\Content\Hymns\Form::class)->name('admin.hymns.edit');
    Route::livewire('/daily-delight', Admin\Content\Devotionals\Editions::class)->name('admin.devotionals.index');
    Route::livewire('/daily-delight/{year}', Admin\Content\Devotionals\Entries::class)->whereNumber('year')->name('admin.devotionals.entries');
    Route::livewire('/daily-delight/{year}/entries/new', Admin\Content\Devotionals\EntryForm::class)->whereNumber('year')->name('admin.devotionals.entry.create');
    Route::livewire('/daily-delight/{year}/entries/{entry}', Admin\Content\Devotionals\EntryForm::class)->whereNumber('year')->name('admin.devotionals.entry.edit');
    Route::livewire('/sunday-school', Admin\Content\SundaySchool\Manuals::class)->name('admin.sunday-school.index');
    Route::livewire('/sunday-school/{year}', Admin\Content\SundaySchool\Manual::class)->whereNumber('year')->name('admin.sunday-school.manual');
    Route::livewire('/sunday-school/{year}/lessons/new', Admin\Content\SundaySchool\LessonForm::class)->whereNumber('year')->name('admin.sunday-school.lesson.create');
    Route::livewire('/sunday-school/{year}/lessons/{lesson}', Admin\Content\SundaySchool\LessonForm::class)->whereNumber('year')->name('admin.sunday-school.lesson.edit');
    Route::livewire('/events', Admin\Content\Events::class)->name('admin.events');
    Route::livewire('/branches', Admin\Content\Branches::class)->name('admin.branches');
    Route::livewire('/pages', Admin\Content\Pages::class)->name('admin.pages');
    Route::livewire('/bible', Admin\Content\Bible::class)->name('admin.bible');

    // Finance
    Route::livewire('/payments', Admin\Finance\Payments::class)->name('admin.payments');
    Route::livewire('/subscriptions', Admin\Finance\Subscriptions::class)->name('admin.subscriptions');
    Route::livewire('/plans', Admin\Finance\Plans::class)->name('admin.plans');

    // Engagement
    Route::livewire('/notifications', Admin\Engagement\Notifications::class)->name('admin.notifications');
    Route::livewire('/mobile-app', Admin\Engagement\MobileApp::class)->name('admin.app');
    Route::livewire('/reports', Admin\Engagement\Reports::class)->name('admin.reports');

    // System
    Route::livewire('/admins', Admin\System\Admins::class)->name('admin.admins');
    Route::livewire('/integrations', Admin\System\Integrations::class)->name('admin.integrations');
    Route::livewire('/operations', Admin\System\Operations::class)->name('admin.operations');
    Route::livewire('/audit-log', Admin\System\AuditLog::class)->name('admin.audit');

    // Interactive API reference for the mobile/frontend engineers.
    Route::get('/api-docs', [OpenApiController::class, 'viewer'])->name('admin.api-docs');
    Route::get('/api-docs/openapi.yaml', [OpenApiController::class, 'spec'])->name('admin.api-docs.spec');

    // Private files (feedback attachments) via short-lived signed URLs, permission-checked.
    Route::get('/files/feedback/{attachment}', [AttachmentController::class, 'feedback'])->name('admin.files.feedback');
});
