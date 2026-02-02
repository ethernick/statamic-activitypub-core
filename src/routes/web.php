<?php

use Illuminate\Support\Facades\Route;
use Ethernick\ActivityPubCore\Http\Controllers\WebFingerController;
use Ethernick\ActivityPubCore\Http\Controllers\ActorController;
use Ethernick\ActivityPubCore\Http\Controllers\ReplyController;
use Ethernick\ActivityPubCore\Http\Controllers\InteractionController;
use Ethernick\ActivityPubCore\Http\Controllers\ActivityController;

Route::get('/.well-known/webfinger', [WebFingerController::class, 'index']);

Route::group(['middleware' => 'web'], function () {
    Route::post('/activitypub/sharedInbox', [ActivityController::class, 'sharedInbox'])
        ->middleware(\Ethernick\ActivityPubCore\Middleware\CaptureInboxFailures::class);

    // Dynamic Route Registration
    Route::get('/@{handle}', [ActorController::class, 'show']);

    Route::get('/@{handle}/notes/{uuid}/replies', [ReplyController::class, 'index']);
    Route::get('/@{handle}/notes/{uuid}/likes', [InteractionController::class, 'likes']);
    Route::get('/@{handle}/notes/{uuid}/shares', [InteractionController::class, 'shares']);
    Route::get('/@{handle}/articles/{uuid}/likes', [InteractionController::class, 'likes']);
    Route::get('/@{handle}/articles/{uuid}/shares', [InteractionController::class, 'shares']);

    Route::get('/@{handle}/outbox', [ActorController::class, 'collection'])->defaults('collection', 'outbox');
    Route::get('/@{handle}/followers', [ActorController::class, 'collection'])->defaults('collection', 'followers');
    Route::get('/@{handle}/following', [ActorController::class, 'collection'])->defaults('collection', 'following');

    Route::get('/@{handle}/{collection}', [ActorController::class, 'collection']);

    // Interaction Routes - Inbox -> ActivityController
    Route::match(['get', 'post'], '/@{handle}/inbox', [ActivityController::class, 'inbox'])
        ->middleware(\Ethernick\ActivityPubCore\Middleware\CaptureInboxFailures::class);
});
