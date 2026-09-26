<?php

use App\Http\Controllers\Api\DeliveryEventController;
use App\Http\Controllers\Api\InboundEmailController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Services\DeliveryEventService;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateApiKey::class])->group(function () {
    Route::get('/emails', [EmailController::class, 'index']);
    Route::post('/emails', [EmailController::class, 'store']);
    Route::get('/emails/{uuid}', [EmailController::class, 'show']);

    Route::get('/inbox/threads', [InboxController::class, 'threads']);
    Route::get('/inbox/threads/{thread}', [InboxController::class, 'showThread']);

    Route::get('/domains', [DomainController::class, 'index']);
    Route::post('/domains', [DomainController::class, 'store']);
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
