<?php

use App\Http\Controllers\Api\App\AiController;
use App\Http\Controllers\Api\App\ChatMessageController;
use App\Http\Controllers\Api\App\ContactController;
use App\Http\Controllers\Api\App\ConversationController;
use App\Http\Controllers\Api\App\DeviceController;
use App\Http\Controllers\Api\App\InboxController;
use App\Http\Controllers\Api\App\MemberController;
use App\Http\Controllers\Api\App\SessionController;
use App\Http\Middleware\EnsureMobileWorkspace;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [SessionController::class, 'show']);
    Route::post('/logout', [SessionController::class, 'destroy']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::delete('/devices', [DeviceController::class, 'destroy']);

    Route::middleware(EnsureMobileWorkspace::class)->group(function () {
        Route::get('/ai', [AiController::class, 'show']);
        Route::post('/ai/compose', [AiController::class, 'compose']);
        Route::post('/inbox/threads/{thread}/suggest', [AiController::class, 'suggestReply'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/summarize', [AiController::class, 'summarize'])->whereNumber('thread');

        Route::get('/members', [MemberController::class, 'index']);

        Route::get('/inbox/threads', [InboxController::class, 'index']);
        Route::post('/inbox/read', [InboxController::class, 'readAll']);
        Route::post('/inbox/send', [InboxController::class, 'compose']);
        Route::get('/inbox/drafts', [InboxController::class, 'drafts']);
        Route::post('/inbox/drafts', [InboxController::class, 'storeDraft']);
        Route::delete('/inbox/drafts/{draft}', [InboxController::class, 'destroyDraft'])->whereNumber('draft');
        Route::get('/inbox/threads/{thread}', [InboxController::class, 'show'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/reply', [InboxController::class, 'reply'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/forward', [InboxController::class, 'forward'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/read', [InboxController::class, 'read'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/unread', [InboxController::class, 'unread'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/archive', [InboxController::class, 'archive'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/spam', [InboxController::class, 'spam'])->whereNumber('thread');
        Route::post('/inbox/threads/{thread}/trash', [InboxController::class, 'trash'])->whereNumber('thread');
        Route::get('/inbox/attachments/{attachment}', [InboxController::class, 'attachment'])->whereNumber('attachment');

        Route::get('/contacts', [ContactController::class, 'index']);
        Route::post('/contacts', [ContactController::class, 'store']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/members', [ConversationController::class, 'addMembers'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/pin', [ConversationController::class, 'pin'])->whereNumber('conversation');

        Route::get('/conversations/{conversation}/messages', [ChatMessageController::class, 'index'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])
            ->middleware('throttle:60,1')
            ->whereNumber('conversation');
        Route::delete('/conversations/{conversation}/messages/{message}', [ChatMessageController::class, 'destroy'])
            ->whereNumber(['conversation', 'message']);
        Route::post('/conversations/{conversation}/read', [ChatMessageController::class, 'read'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/delivered', [ChatMessageController::class, 'delivered'])->whereNumber('conversation');
        Route::post('/conversations/{conversation}/typing', [ChatMessageController::class, 'typing'])
            ->middleware('throttle:30,1')
            ->whereNumber('conversation');
        Route::get('/chat/attachments/{attachment}', [ChatMessageController::class, 'attachment'])->whereNumber('attachment');
    });
});
