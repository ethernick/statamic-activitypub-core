<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ethernick\ActivityPubCore\Services\HttpSignature;

class QuoteRequestController
{
    /**
     * Handle incoming QuoteRequest activity (FEP-044f)
     *
     * @param array $payload The QuoteRequest activity payload
     * @param mixed $localActor The local actor receiving the request
     * @param mixed $externalActor The external actor making the request
     * @return void
     */
    public function handleQuoteRequest(array $payload, mixed $localActor, mixed $externalActor): void
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        $objectId = $payload['object'] ?? null;
        $quoteRequestId = $payload['id'] ?? null;

        Log::info("QuoteRequestController: Processing QuoteRequest from $actorId for object $objectId");

        if (!$objectId || !$quoteRequestId) {
            Log::error('QuoteRequestController: Missing object or id in QuoteRequest activity');
            return;
        }

        // Check if the local actor allows quotes
        // This is determined by the allow_quotes setting in activitypub.yaml
        $settingsPath = resource_path('settings/activitypub.yaml');
        $allowQuotes = false;

        if (file_exists($settingsPath)) {
            $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
            $allowQuotes = $settings['allow_quotes'] ?? false;
        }

        if (!$allowQuotes) {
            Log::info("QuoteRequestController: Quotes not allowed, not sending Accept");
            return;
        }

        // Send Accept activity in response to the QuoteRequest
        $this->sendAcceptActivity($localActor, $payload, $externalActor);

        Log::info("QuoteRequestController: Accepted QuoteRequest from $actorId");
    }

    /**
     * Send an Accept activity in response to a QuoteRequest
     *
     * @param mixed $localActor The local actor sending the Accept
     * @param array $quoteRequestPayload The original QuoteRequest activity
     * @param mixed $remoteActor The remote actor who sent the QuoteRequest
     * @return void
     */
    protected function sendAcceptActivity(mixed $localActor, array $quoteRequestPayload, mixed $remoteActor): void
    {
        $inbox = $remoteActor->get('inbox_url');
        if (!$inbox) {
            Log::warning('QuoteRequestController: No inbox URL for remote actor');
            return;
        }

        $localActorId = $this->sanitizeUrl(url('@' . $localActor->slug()));

        // Per FEP-044f, the Accept's id should be the URL of the post being quoted
        $objectId = $quoteRequestPayload['object'] ?? null;

        // Generate a QuoteAuthorization stamp URL
        $stampGuid = Str::uuid();
        $stampUrl = $objectId . '#quote-authorization-' . $stampGuid;

        // Build the Accept activity according to FEP-044f
        $activity = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                [
                    'QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest',
                ],
            ],
            'id' => $objectId,
            'type' => 'Accept',
            'actor' => $localActorId,
            'to' => $quoteRequestPayload['actor'] ?? null,
            'object' => $quoteRequestPayload,
            'result' => $stampUrl,
        ];

        $jsonBody = json_encode($activity);
        $privateKey = $localActor->get('private_key');

        if (!$privateKey) {
            Log::warning('QuoteRequestController: No private key for local actor');
            return;
        }

        $headers = HttpSignature::sign($inbox, $localActorId, $privateKey, $jsonBody);
        if (empty($headers)) {
            Log::warning('QuoteRequestController: Failed to sign Accept activity');
            return;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->post($inbox);

            Log::info("QuoteRequestController: Sent Accept activity to $inbox", [
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error("QuoteRequestController: Failed to send Accept activity: " . $e->getMessage());
        }
    }

    /**
     * Sanitize URL by removing www subdomain
     *
     * @param string $url
     * @return string
     */
    protected function sanitizeUrl(string $url): string
    {
        return str_replace('://www.', '://', $url);
    }
}
