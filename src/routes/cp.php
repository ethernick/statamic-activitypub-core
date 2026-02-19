<?php

use Illuminate\Support\Facades\Route;
use Ethernick\ActivityPubCore\Http\Controllers\ActivityPubSettingsController;
use Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController;
use Ethernick\ActivityPubCore\Http\Controllers\CP\LinkPreviewController;

use Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController;

Route::group(['prefix' => 'activitypub'], function () {
    Route::get('settings', [ActivityPubSettingsController::class, 'index'])->name('activitypub.settings.index');
    Route::post('settings', [ActivityPubSettingsController::class, 'update'])->name('activitypub.settings.update');
    Route::get('logs', [ActivityPubSettingsController::class, 'logs'])->name('activitypub.logs');
    Route::post('logs/clear', [ActivityPubSettingsController::class, 'clearLogs'])->name('activitypub.logs.clear');

    // Inbox
    Route::get('inbox', [InboxController::class, 'index'])->name('activitypub.inbox.index');
    Route::get('inbox/api', [InboxController::class, 'api'])->name('activitypub.inbox.api');
    Route::get('inbox/thread/{id}', [InboxController::class, 'thread'])->name('activitypub.thread');
    Route::get('inbox/activities/{id}', [InboxController::class, 'activities'])->name('activitypub.inbox.activities');
    Route::post('inbox/reply', [InboxController::class, 'reply'])->name('activitypub.inbox.reply');
    Route::post('inbox/notes', [InboxController::class, 'storeNote'])->name('activitypub.inbox.store-note');
    Route::post('inbox/polls', [InboxController::class, 'storePoll'])->name('activitypub.inbox.store-poll');
    Route::put('inbox/notes/{id}', [InboxController::class, 'updateNote'])->name('activitypub.inbox.update-note');
    Route::post('inbox/delete', [InboxController::class, 'destroy'])->name('activitypub.inbox.delete');
    Route::post('inbox/link-preview', [LinkPreviewController::class, 'show'])->name('activitypub.inbox.link-preview');
    Route::post('inbox/batch-link-preview', [InboxController::class, 'batchLinkPreview'])->name('activitypub.inbox.batch-link-preview');
    Route::post('inbox/batch-enrichment', [InboxController::class, 'batchEnrichment'])->name('activitypub.inbox.batch-enrichment');

    // Activities route removed
    Route::get('following', [FollowController::class, 'following'])->name('activitypub.following.index');
    Route::get('following/api', [FollowController::class, 'apiFollowing'])->name('activitypub.following.api');
    Route::get('followers', [FollowController::class, 'followers'])->name('activitypub.followers.index');
    Route::get('followers/api', [FollowController::class, 'apiFollowers'])->name('activitypub.followers.api');

    // Search and Follow (Ajax)
    Route::post('search', [FollowController::class, 'search'])->name('activitypub.search');
    Route::post('follow', [FollowController::class, 'follow'])->name('activitypub.follow');
    Route::post('unfollow', [FollowController::class, 'unfollow'])->name('activitypub.unfollow');
    Route::post('block', [FollowController::class, 'block'])->name('activitypub.block');
    Route::post('unblock', [FollowController::class, 'unblock'])->name('activitypub.unblock');

    // Likes
    Route::post('like', [\Ethernick\ActivityPubCore\Http\Controllers\LikeController::class, 'store'])->name('activitypub.like');
    Route::post('unlike', [\Ethernick\ActivityPubCore\Http\Controllers\LikeController::class, 'destroy'])->name('activitypub.unlike');

    // Announce (Boost)
    Route::post('announce', [\Ethernick\ActivityPubCore\Http\Controllers\AnnounceController::class, 'store'])->name('activitypub.announce');
    Route::post('undo-announce', [\Ethernick\ActivityPubCore\Http\Controllers\AnnounceController::class, 'destroy'])->name('activitypub.undo-announce');
});
