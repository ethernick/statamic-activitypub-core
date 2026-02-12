<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ethernick\ActivityPubCore\Services\HttpSignature;

class AcceptQuoteRequest extends Command
{
    protected $signature = 'activitypub:accept-quote-request {activity_id?}';

    protected $description = 'Send an Accept response for a pending QuoteRequest';

    public function handle(): int
    {
        $activityId = $this->argument('activity_id');

        // Find the QuoteRequest activity
        if ($activityId) {
            $quoteRequest = Entry::find($activityId);
            if (!$quoteRequest || $quoteRequest->get('type') !== 'QuoteRequest') {
                $this->error("Activity {$activityId} not found or is not a QuoteRequest");
                return 1;
            }
        } else {
            $quoteRequest = Entry::query()
                ->where('collection', 'activities')
                ->where('type', 'QuoteRequest')
                ->orderBy('date', 'desc')
                ->first();
        }

        if (!$quoteRequest) {
            $this->error("No QuoteRequest found.");
            $this->info("Listing recent activities:");
            $recent = Entry::query()
                ->where('collection', 'activities')
                ->orderBy('date', 'desc')
                ->limit(10)
                ->get();
            foreach ($recent as $activity) {
                $this->line("  - {$activity->get('type')} from {$activity->get('activitypub_id')}");
            }
            return 1;
        }

        $this->info("Found QuoteRequest: {$quoteRequest->get('activitypub_id')}");

        // Get the full JSON payload
        $payload = json_decode($quoteRequest->get('activitypub_json'), true);

        if (!$payload) {
            $this->error("Error: Could not decode activity JSON");
            return 1;
        }

        $this->info("Actor: {$payload['actor']}");
        $this->info("Object: {$payload['object']}");

        // Find the local actor who received this request
        $objectId = $payload['object'] ?? null;
        $localNote = null;

        if ($objectId) {
            // Try to find by activitypub_id first
            $localNote = Entry::query()
                ->where('collection', 'notes')
                ->where('activitypub_id', $objectId)
                ->first();

            // Fallback: extract slug from URL and search by slug
            if (!$localNote && preg_match('#/notes/([a-f0-9-]+)#', $objectId, $matches)) {
                $slug = $matches[1];
                $this->info("Trying to find note by slug: {$slug}");
                $localNote = Entry::query()
                    ->where('collection', 'notes')
                    ->where('slug', $slug)
                    ->first();
            }
        }

        if (!$localNote) {
            $this->error("Error: Could not find the local note that was quoted");
            return 1;
        }

        // Get the local actor who owns this note
        $actorRefs = $localNote->get('actor');
        $localActorId = is_array($actorRefs) ? ($actorRefs[0] ?? null) : $actorRefs;

        if (!$localActorId) {
            $this->error("Error: Note has no actor");
            return 1;
        }

        $localActor = Entry::find($localActorId);

        if (!$localActor) {
            $this->error("Error: Could not find local actor");
            return 1;
        }

        $this->info("Local actor: {$localActor->get('title')} (@{$localActor->slug()})");

        // Find or resolve the external actor
        $externalActorId = $payload['actor'] ?? null;
        $externalActor = Entry::query()
            ->where('collection', 'actors')
            ->where('activitypub_id', $externalActorId)
            ->first();

        if (!$externalActor) {
            $this->error("Warning: External actor not found in database, cannot send Accept");
            return 1;
        }

        $this->info("External actor: {$externalActor->get('title')}");

        // Get the inbox URL
        $inbox = $externalActor->get('inbox_url');

        if (!$inbox) {
            $this->error("Error: External actor has no inbox URL");
            return 1;
        }

        $this->info("Inbox: {$inbox}");

        // Check if quotes are enabled
        $settingsPath = resource_path('settings/activitypub.yaml');
        $allowQuotes = false;

        if (file_exists($settingsPath)) {
            $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
            $allowQuotes = $settings['allow_quotes'] ?? false;
        }

        if (!$allowQuotes) {
            $this->warn("Warning: 'allow_quotes' is not enabled in settings.");
            if (!$this->confirm('Do you want to send Accept anyway?', false)) {
                $this->info('Aborted.');
                return 0;
            }
        }

        // Build the Accept activity
        $localActorUrl = str_replace('://www.', '://', url('@' . $localActor->slug()));

        // Per FEP-044f, the Accept's id should be the URL of the post being quoted
        $noteUrl = $localNote->absoluteUrl();

        // Generate a QuoteAuthorization stamp URL
        $stampGuid = Str::uuid();
        $stampUrl = $noteUrl . '#quote-authorization-' . $stampGuid;

        $acceptActivity = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                [
                    'QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest',
                ],
            ],
            'id' => $noteUrl,
            'type' => 'Accept',
            'actor' => $localActorUrl,
            'to' => $externalActorId,
            'object' => $payload,
            'result' => $stampUrl,
        ];

        $this->line("\nAccept activity:");
        $this->line(json_encode($acceptActivity, JSON_PRETTY_PRINT));

        // Get private key
        $privateKey = $localActor->get('private_key');

        if (!$privateKey) {
            $this->error("Error: Local actor has no private key");
            return 1;
        }

        // Sign and send
        $jsonBody = json_encode($acceptActivity);
        $headers = HttpSignature::sign($inbox, $localActorUrl, $privateKey, $jsonBody);

        if (empty($headers)) {
            $this->error("Error: Failed to sign Accept activity");
            return 1;
        }

        $this->info("\nSending Accept to {$inbox}...");

        try {
            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->post($inbox);

            $this->info("Response status: {$response->status()}");

            if ($response->successful()) {
                $this->info("✓ Accept sent successfully!");
                return 0;
            } else {
                $this->error("✗ Request failed");
                $this->line("Response body: {$response->body()}");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("✗ Error sending Accept: {$e->getMessage()}");
            return 1;
        }
    }
}
