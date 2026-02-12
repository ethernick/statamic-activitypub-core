<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface;

class AcceptController implements ActivityHandlerInterface
{
    public static function getHandledActivityTypes(): array
    {
        return ['Accept:QuoteRequest'];
    }

    public function getSupportedActivities(): array
    {
        return ['Accept'];
    }

    public function getSupportedObjectTypes(): array
    {
        return ['QuoteRequest'];
    }

    /**
     * Handle incoming Accept activity (response to our QuoteRequest)
     */
    public function handleAccept(array $payload, mixed $localActor, mixed $externalActor): void
    {
        Log::info("AcceptController: Processing Accept activity", [
            'actor' => $payload['actor'] ?? 'unknown',
            'type' => $payload['type'] ?? 'unknown',
        ]);

        // Get the embedded QuoteRequest object
        $quoteRequest = $payload['object'] ?? null;

        if (!$quoteRequest || !is_array($quoteRequest)) {
            Log::warning("AcceptController: No QuoteRequest object in Accept activity");
            return;
        }

        // Verify this is a QuoteRequest
        if (($quoteRequest['type'] ?? null) !== 'QuoteRequest') {
            Log::warning("AcceptController: Object is not a QuoteRequest", [
                'type' => $quoteRequest['type'] ?? 'unknown',
            ]);
            return;
        }

        // Get the QuoteRequest ID to find our pending quote
        $quoteRequestId = $quoteRequest['id'] ?? null;

        if (!$quoteRequestId) {
            Log::warning("AcceptController: QuoteRequest has no ID");
            return;
        }

        // Find the quote note that sent this request
        $quoteNote = Entry::query()
            ->where('collection', 'notes')
            ->where('quote_request_id', $quoteRequestId)
            ->first();
        if (!$quoteNote) {
            Log::warning("AcceptController: No quote note found with request ID: {$quoteRequestId}");
            return;
        }

        // Extract the authorization stamp from the 'result' field (per FEP-044f)
        $authorizationStamp = $payload['result'] ?? null;

        if (!$authorizationStamp) {
            Log::warning("AcceptController: Accept has no 'result' (authorization stamp)");
            // Still mark as accepted, just without stamp
        }

        // Update the quote note
        $quoteNote->set('quote_authorization_status', 'accepted');
        if ($authorizationStamp) {
            $quoteNote->set('quote_authorization_stamp', $authorizationStamp);
        }
        $quoteNote->set('_quote_approved', true); // Flag for AutoGenerateActivityListener
        $quoteNote->save(); // Use save() to trigger AutoGenerateActivityListener

        Log::info("AcceptController: Quote authorization accepted", [
            'quote_note' => $quoteNote->id(),
            'stamp' => $authorizationStamp,
        ]);

        // Now that we have authorization, queue the Create activity to be sent
        // This will be handled by the normal federation flow
        $this->queueCreateActivity($quoteNote);
    }

    /**
     * Queue a Create activity for the approved quote
     */
    protected function queueCreateActivity(\Statamic\Contracts\Entries\Entry $quoteNote): void
    {
        // Check if a Create activity already exists
        $existingActivity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Create')
            ->get()
            ->filter(function ($activity) use ($quoteNote) {
                $object = $activity->get('object');
                if (is_array($object)) {
                    $object = $object[0] ?? null;
                }
                return $object === $quoteNote->id();
            })
            ->first();

        if ($existingActivity) {
            Log::info("AcceptController: Create activity already exists for quote", [
                'activity' => $existingActivity->id(),
            ]);
            // Re-queue it to send now that we have authorization
            \Ethernick\ActivityPubCore\Jobs\SendActivityPubPost::dispatch($existingActivity->id())
                ->onQueue('activitypub-outbox');
            return;
        }

        // Create a new Create activity
        $actorId = $quoteNote->get('actor');
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        if (!$actorId) {
            Log::warning("AcceptController: Quote note has no actor");
            return;
        }

        $activity = Entry::make()
            ->collection('activities')
            ->slug(uniqid('activity-'))
            ->data([
                'title' => 'Create ' . $quoteNote->get('title', 'Quote'),
                'content' => 'Created a quote',
                'type' => 'Create',
                'actor' => [$actorId],
                'object' => [$quoteNote->id()],
                'published' => true,
                'date' => now()->format('Y-m-d H:i:s'),
                'activitypub_collections' => ['outbox'],
            ]);

        $activity->save();

        Log::info("AcceptController: Created Create activity for approved quote", [
            'activity' => $activity->id(),
            'quote' => $quoteNote->id(),
        ]);
    }
}
