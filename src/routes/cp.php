<?php

use Illuminate\Support\Facades\Route;

Route::get('activitypub/settings', 'Ethernick\ActivityPubCore\Http\Controllers\ActivityPubSettingsController@index')->name('activitypub.settings.index');
Route::post('activitypub/settings', 'Ethernick\ActivityPubCore\Http\Controllers\ActivityPubSettingsController@update')->name('activitypub.settings.update');
Route::get('activitypub/logs', 'Ethernick\ActivityPubCore\Http\Controllers\ActivityPubSettingsController@logs')->name('activitypub.logs');
Route::post('activitypub/logs/clear', 'Ethernick\ActivityPubCore\Http\Controllers\ActivityPubSettingsController@clearLogs')->name('activitypub.logs.clear');

Route::get('activitypub/actor-lookup', 'Ethernick\ActivityPubCore\Http\Controllers\CP\ActorLookupController@index')->name('activitypub.actor-lookup.index');
Route::post('activitypub/actor-lookup', 'Ethernick\ActivityPubCore\Http\Controllers\CP\ActorLookupController@lookup')->name('activitypub.actor-lookup.lookup');

Route::get('activitypub/tools', 'Ethernick\ActivityPubCore\Http\Controllers\CP\ToolsController@index')->name('activitypub.tools.index');
Route::get('activitypub/queue', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@index')->name('activitypub.queue.index');
Route::get('activitypub/queue/status', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@status')->name('activitypub.queue.status');
Route::get('activitypub/queue/pending', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@pending')->name('activitypub.queue.pending');
Route::delete('activitypub/queue/pending/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@deletePending')->name('activitypub.queue.pending.delete');
Route::post('activitypub/queue/pending/flush', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@flushPendingByType')->name('activitypub.queue.pending.flush');
Route::get('activitypub/queue/failed', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@failed')->name('activitypub.queue.failed');
Route::post('activitypub/queue/retry-ap', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@retryFailedActivityPub')->name('activitypub.queue.failed.retry-ap');
Route::post('activitypub/queue/flush-ap', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@flushFailedActivityPub')->name('activitypub.queue.failed.flush-ap');
Route::post('activitypub/queue/retry/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@retry')->name('activitypub.queue.retry');
Route::delete('activitypub/queue/failed/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@deleteFailed')->name('activitypub.queue.failed.delete');
Route::post('activitypub/queue/flush', 'Ethernick\ActivityPubCore\Http\Controllers\CP\QueueController@flushFailed')->name('activitypub.queue.flushFailed');

Route::get('activitypub/inbox', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@index')->name('activitypub.inbox.index');
Route::get('activitypub/inbox/api', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@api')->name('activitypub.inbox.api');
Route::get('activitypub/inbox/thread/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@thread')->name('activitypub.thread');
Route::get('activitypub/inbox/activities/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@activities')->name('activitypub.inbox.activities');
Route::post('activitypub/inbox/reply', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@reply')->name('activitypub.inbox.reply');
Route::post('activitypub/inbox/notes', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@storeNote')->name('activitypub.inbox.store-note');
Route::post('activitypub/inbox/polls', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@storePoll')->name('activitypub.inbox.store-poll');
Route::put('activitypub/inbox/notes/{id}', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@updateNote')->name('activitypub.inbox.update-note');
Route::post('activitypub/inbox/delete', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@destroy')->name('activitypub.inbox.delete');
Route::post('activitypub/inbox/link-preview', 'Ethernick\ActivityPubCore\Http\Controllers\CP\LinkPreviewController@show')->name('activitypub.inbox.link-preview');
Route::post('activitypub/inbox/batch-link-preview', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@batchLinkPreview')->name('activitypub.inbox.batch-link-preview');
Route::post('activitypub/inbox/batch-enrichment', 'Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController@batchEnrichment')->name('activitypub.inbox.batch-enrichment');

Route::get('activitypub/following', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@following')->name('activitypub.following.index');
Route::get('activitypub/following/api', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@apiFollowing')->name('activitypub.following.api');
Route::get('activitypub/followers', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@followers')->name('activitypub.followers.index');
Route::get('activitypub/followers/api', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@apiFollowers')->name('activitypub.followers.api');

Route::post('activitypub/search', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@search')->name('activitypub.search');
Route::post('activitypub/follow', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@follow')->name('activitypub.follow');
Route::post('activitypub/unfollow', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@unfollow')->name('activitypub.unfollow');
Route::post('activitypub/block', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@block')->name('activitypub.block');
Route::post('activitypub/unblock', 'Ethernick\ActivityPubCore\Http\Controllers\CP\FollowController@unblock')->name('activitypub.unblock');

Route::post('activitypub/like', 'Ethernick\ActivityPubCore\Http\Controllers\LikeController@store')->name('activitypub.like');
Route::post('activitypub/unlike', 'Ethernick\ActivityPubCore\Http\Controllers\LikeController@destroy')->name('activitypub.unlike');

Route::post('activitypub/announce', 'Ethernick\ActivityPubCore\Http\Controllers\AnnounceController@store')->name('activitypub.announce');
Route::post('activitypub/undo-announce', 'Ethernick\ActivityPubCore\Http\Controllers\AnnounceController@destroy')->name('activitypub.undo-announce');
