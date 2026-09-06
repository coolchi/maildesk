<?php

use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Middleware\AuthenticateApiKey;
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
