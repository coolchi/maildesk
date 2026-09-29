<?php

use App\Http\Controllers\Api\DeliveryEventController;
use App\Http\Controllers\Api\InboundEmailController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\ContactController as MobileContactController;
use App\Http\Controllers\Api\Mobile\EmailController as MobileEmailController;
use App\Http\Controllers\Api\Mobile\InboxController as MobileInboxController;
use App\Http\Controllers\Api\MonipayWebhookController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\SegmentController;
use App\Http\Controllers\Api\V1\SuppressionController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureApiAccountActive;
use App\Http\Middleware\EnsureMobileWorkspaceEntitled;
use App\Services\DeliveryEventService;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateApiKey::class, EnsureApiAccountActive::class])->group(function () {
    Route::get('/emails', [EmailController::class, 'index']);
    Route::post('/emails', [EmailController::class, 'store']);
    Route::get('/emails/{uuid}', [EmailController::class, 'show']);

    Route::get('/inbox/threads', [InboxController::class, 'threads']);
    Route::get('/inbox/threads/{thread}', [InboxController::class, 'showThread']);

    Route::get('/domains', [DomainController::class, 'index']);
    Route::post('/domains', [DomainController::class, 'store']);

    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);

    Route::get('/segments', [SegmentController::class, 'index']);
    Route::get('/suppressions', [SuppressionController::class, 'index']);
    Route::get('/templates', [TemplateController::class, 'index']);

    Route::post('/events', [EventController::class, 'store']);
});

// Provider webhooks for received mail (signature-verified, no API key).
Route::post('/inbound/{driver}', InboundEmailController::class)
    ->whereIn('driver', ['resend', 'generic'])
    ->middleware('throttle:600,1')
    ->name('inbound.receive');

// Provider delivery events: delivered, bounced, complained, opened.
Route::post('/events/{driver}', DeliveryEventController::class)
    ->whereIn('driver', array_keys(DeliveryEventService::DRIVERS))
    ->middleware('throttle:600,1')
    ->name('events.receive');

// Monipay payment events (HMAC-SHA512 signature-verified, no API key).
Route::post('/payments/monipay/webhook', MonipayWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('payments.monipay.webhook');

// Mobile API routes (Sanctum token auth)
Route::prefix('mobile')->name('mobile.')->group(function () {
    Route::post('/auth/login', [MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureMobileWorkspaceEntitled::class])->group(function () {
        Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [MobileAuthController::class, 'me'])->name('auth.me');

        Route::get('/inbox', [MobileInboxController::class, 'index'])->name('inbox.index');
        Route::get('/inbox/{thread}', [MobileInboxController::class, 'show'])->whereNumber('thread')->name('inbox.show');
        Route::patch('/inbox/{thread}/read', [MobileInboxController::class, 'markRead'])->whereNumber('thread')->name('inbox.read');
        Route::post('/inbox/{thread}/archive', [MobileInboxController::class, 'toggleArchive'])->whereNumber('thread')->name('inbox.archive');
        Route::post('/inbox/{thread}/spam', [MobileInboxController::class, 'toggleSpam'])->whereNumber('thread')->name('inbox.spam');
        Route::post('/inbox/{thread}/trash', [MobileInboxController::class, 'toggleTrash'])->whereNumber('thread')->name('inbox.trash');
        Route::post('/inbox/{thread}/reply', [MobileInboxController::class, 'reply'])->whereNumber('thread')->name('inbox.reply');

        Route::post('/emails', [MobileEmailController::class, 'store'])->name('emails.store');

        Route::get('/contacts', [MobileContactController::class, 'index'])->name('contacts.index');
        Route::post('/contacts', [MobileContactController::class, 'store'])->name('contacts.store');
    });
});
