<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ethernick\ActivityPubCore\Services\HttpSignature;
use Illuminate\Support\Str;

class SendQuoteRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 120;

    public string $quoteNoteId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $quoteNoteId)
    {
        $this->quoteNoteId = $quoteNoteId;
    }

    /**
     * Execute the job - Send QuoteRequest per FEP-044f
     */
    public function handle(): void
    {
        $quoteNote = Entry::find($this->quoteNoteId);

        if (!$quoteNote) {
            Log::error("SendQuoteRequest: Quote note not found: {$this->quoteNoteId}");
            return;
        }

        // Get the quoted entry
        $quoteOf = $quoteNote->get('quote_of');
        if (!$quoteOf || !is_array($quoteOf)) {
            Log::warning("SendQuoteRequest: No quote_of found for note: {$this->quoteNoteId}");
            return;
        }

        $quotedEntryId = $quoteOf[0];
        $quotedEntry = Entry::find($quotedEntryId);

        if (!$quotedEntry) {
            Log::error("SendQuoteRequest: Quoted entry not found: {$quotedEntryId}");
            return;
        }

        // If this is a local note, we can auto-approve
        $quotedNoteApId = $quotedEntry->get('activitypub_id');
        $siteUrl = url('/');
        $isLocalUrl = $quotedNoteApId && str_starts_with($quotedNoteApId, $siteUrl);

        if ($quotedEntry->get('is_internal') !== false || $isLocalUrl) {
            Log::info("SendQuoteRequest: Internal/Local quote detected, auto-approving", [
                'id' => $quotedEntryId,
                'is_internal' => $quotedEntry->get('is_internal'),
                'is_local_url' => $isLocalUrl
            ]);

            $quoteNote->set('quote_authorization_status', 'accepted');

            // Generate unique authorization URL with fragment identifier if we have an AP ID
            if ($quotedNoteApId) {
                $authorizationUrl = $quotedNoteApId . '#quote-authorization-' . \Illuminate\Support\Str::uuid();
                $quoteNote->set('quote_authorization_stamp', $authorizationUrl);
            }

            $quoteNote->set('_quote_approved', true); // Flag for AutoGenerateActivityListener
            $quoteNote->save(); // Use save() to trigger AutoGenerateActivityListener
            return;
        }

        // For EXTERNAL quotes, ALWAYS send QuoteRequest (per FEP-044f)
        // Even with automaticApproval, the remote server needs to generate the authorization stamp

        // Get the quoted note's ActivityPub ID and author
        $quotedNoteApId = $quotedEntry->get('activitypub_id');
        if (!$quotedNoteApId) {
            Log::error("SendQuoteRequest: Quoted entry has no activitypub_id: {$quotedEntryId}");
            return;
        }

        // Get quoted note's author
        $quotedActorId = $quotedEntry->get('actor');
        if (is_array($quotedActorId)) {
            $quotedActorId = $quotedActorId[0] ?? null;
        }

        if (!$quotedActorId) {
            Log::error("SendQuoteRequest: Quoted entry has no actor: {$quotedEntryId}");
            return;
        }

        $quotedActor = Entry::find($quotedActorId);
        if (!$quotedActor) {
            Log::error("SendQuoteRequest: Quoted actor not found: {$quotedActorId}");
            return;
        }

        $quotedActorInbox = $quotedActor->get('inbox_url');
        if (!$quotedActorInbox) {
            Log::error("SendQuoteRequest: Quoted actor has no inbox: {$quotedActorId}");
            return;
        }

        // Get our local actor
        $localActorId = $quoteNote->get('actor');
        if (is_array($localActorId)) {
            $localActorId = $localActorId[0] ?? null;
        }

        $localActor = Entry::find($localActorId);
        if (!$localActor) {
            Log::error("SendQuoteRequest: Local actor not found: {$localActorId}");
            return;
        }

        $localActorUrl = str_replace('://www.', '://', url('@' . $localActor->slug()));
        $privateKey = $localActor->get('private_key');

        if (!$privateKey) {
            Log::error("SendQuoteRequest: Local actor has no private key: {$localActorId}");
            return;
        }

        // Build QuoteRequest activity per FEP-044f
        $requestId = $quoteNote->absoluteUrl() . '#quote-request-' . Str::uuid();

        // Build the instrument (the quote post itself) per FEP-044f spec
        // "The quote post SHOULD be inlined in the instrument property"
        $instrument = [
            'type' => 'Note',
            'id' => $quoteNote->absoluteUrl(),
            'attributedTo' => $localActorUrl,
            'content' => $quoteNote->get('content'),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$localActorUrl . '/followers'],
            // Include all quote reference fields for interoperability
            'quote' => $quotedNoteApId,
            '_misskey_quote' => $quotedNoteApId,
            'quoteUri' => $quotedNoteApId,
            'quoteUrl' => $quotedNoteApId,
        ];

        $activity = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                [
                    'QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest',
                    'quote' => 'https://w3id.org/fep/044f#quote',
                    'quoteUri' => 'http://fedibird.com/ns#quoteUri',
                    '_misskey_quote' => 'https://misskey-hub.net/ns#_misskey_quote',
                ],
            ],
            'id' => $requestId,
            'type' => 'QuoteRequest',
            'actor' => $localActorUrl,
            'object' => $quotedNoteApId,
            'instrument' => $instrument,
            'to' => $quotedActor->get('activitypub_id') ?: $quotedActorInbox,
        ];

        $jsonBody = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Sign and send
        $headers = HttpSignature::sign($quotedActorInbox, $localActorUrl, $privateKey, $jsonBody);

        if (empty($headers)) {
            Log::error("SendQuoteRequest: Failed to sign request");
            throw new \RuntimeException("Failed to sign QuoteRequest");
        }

        try {
            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->timeout(30)
                ->post($quotedActorInbox);

            if ($response->successful()) {
                // Check if response contains Accept activity (automaticApproval case)
                $responseBody = $response->body();
                $responseData = json_decode($responseBody, true);

                Log::info("SendQuoteRequest: Sent successfully to {$quotedActorInbox}", [
                    'status' => $response->status(),
                    'quote_note' => $this->quoteNoteId,
                    'response_content_type' => $response->header('Content-Type'),
                    'response_body_length' => strlen($responseBody),
                    'response_body_preview' => substr($responseBody, 0, 500),
                    'response_is_json' => $responseData !== null,
                    'response_type' => $responseData['type'] ?? 'none',
                ]);

                if ($responseData && isset($responseData['type']) && $responseData['type'] === 'Accept') {
                    // Extract authorization stamp from 'result' field
                    $authorizationStamp = $responseData['result'] ?? null;

                    Log::info("SendQuoteRequest: Received immediate Accept (automaticApproval)", [
                        'quote_note' => $this->quoteNoteId,
                        'authorization_stamp' => $authorizationStamp,
                        'full_accept_response' => $responseData, // Log full Accept for debugging
                    ]);

                    $quoteNote->set('quote_authorization_status', 'accepted');
                    if ($authorizationStamp) {
                        $quoteNote->set('quote_authorization_stamp', $authorizationStamp);
                    }
                    $quoteNote->set('_quote_approved', true);
                    $quoteNote->save(); // Use save() to trigger AutoGenerateActivityListener
                } else {
                    // No immediate Accept, mark as pending (will receive Accept in inbox later)
                    Log::info("SendQuoteRequest: No immediate Accept, marking as pending", [
                        'quote_note' => $this->quoteNoteId,
                        'reason' => $responseData ? 'Response type is ' . ($responseData['type'] ?? 'unknown') : 'Response not JSON',
                    ]);
                    $quoteNote->set('quote_authorization_status', 'pending');
                    $quoteNote->set('quote_request_id', $requestId);
                    $quoteNote->saveQuietly();
                }
            } else {
                Log::warning("SendQuoteRequest: Request failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException("QuoteRequest returned status " . $response->status());
            }
        } catch (\Exception $e) {
            Log::error("SendQuoteRequest: Exception: " . $e->getMessage());
            throw $e;
        }
    }
}
