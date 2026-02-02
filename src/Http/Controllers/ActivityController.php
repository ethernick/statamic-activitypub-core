<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Http\Request;
use Ethernick\ActivityPubCore\Services\ActivityDispatcher;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Ethernick\ActivityPubCore\Services\HttpSignature;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

class ActivityController extends BaseActivityController
{
    protected $dispatcher;

    public function __construct(ActivityDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function inbox(Request $request, $handle)
    {
        // 1. Logging
        $this->logRequest($request);

        // 2. Resolve Actor
        // Logic duplicated closer to ActorController::findActor but we can reuse BaseController or just logic here.
        // Assuming BaseObjectController (which ActorController extends) has logic? No, ActorController overrides show/find.
        // Let's rely on simple find here or helper.
        // Actually `ActorHelper` or similar would be good, but let's implement inline for now.

        $actorEntry = Entry::query()
            ->where('collection', 'actors')
            ->where('slug', $handle)
            ->first(); // Simplistic. ActorController::findActor is more robust (handles redirects/aliases potentially?)

        if (!$actorEntry) {
            return response()->json(['error' => 'Actor not found'], 404);
        }

        if ($request->isMethod('POST')) {
            return $this->handleInboxPost($request, $actorEntry);
        }

        return response()->json(['message' => 'Inbox available.'], 200);
    }

    public function sharedInbox(Request $request)
    {
        $this->logRequest($request, 'SHARED_INBOX');

        if ($request->isMethod('POST')) {
            // Simplified SharedInbox logic:
            // 1. Identify recipients (to/cc matching local actors)
            // 2. Dispatch for each recipient (or queue)

            // For now, let's keep the logic similar to ActorController::sharedInbox
            // BUT, we want to unify.
            // We can iterate recipients and call handleInboxPost for each.

            return $this->processSharedInboxPayload($request);
        }

        return response()->json(['message' => 'Shared Inbox available.'], 200);
    }

    protected function handleInboxPost(Request $request, $actor)
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        // Blocklist & Signature Check (Delegate or Inline)
        // Ignoring Blocklist for brevity in this initial file, but should be added.

        // Signature Logic Reuse? 
        // We need to move verifySignature from ActorController to a trait or service if not already.
        // It resides in ActorController protected method currently.
        // Implementation Plan said "Includes verifySignature logic (moved/extracted)".

        if (!$this->verifyRequestSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Queueing Strategy
        // We retain the "Queue" approach for most things, but "Immediate" for Follow/Accept.
        // The `ActivityDispatcher` currently runs synchronously.
        // So we should Queue the *Payload* and let the Job call the Dispatcher.
        // OR we Dispatch here if it's high priority.

        $type = $payload['type'] ?? 'Unknown';

        if (in_array($type, ['Follow', 'Accept'])) {
            // Immediate Dispatch
            $externalActor = $this->resolveExternalActor($payload['actor'] ?? null);
            if ($externalActor) {
                $this->dispatcher->dispatch($payload, $actor, $externalActor);
                return response()->json(['success' => true], 200);
            }
        }

        // Queue Everything Else
        $queue = new FileQueue();
        $queueData = [
            'payload' => $payload,
            'local_actor_id' => $actor->id(),
            'external_actor_url' => $payload['actor'] ?? null,
            // 'external_actor_id' => ... optimization
        ];

        $queue->push('inbox', $queueData);

        return response()->json(['success' => true, 'queued' => true], 202);
    }

    // Placeholder helpers
    protected function logRequest($request, $prefix = '')
    {
        // ... logging logic
    }

    protected function processSharedInboxPayload($request)
    {
        // ... (Similar to ActorController::sharedInbox)
        return response()->json(['message' => 'Accepted'], 202);
    }

    protected function resolveExternalActor($url)
    {
        // ... 
        return null;
    }

}
