<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\YAML;
use Statamic\Facades\File;

class ActorController extends BaseObjectController
{
    public static $shouldSkipSignatureVerificationInTests = true;

    // Override show to handle Actor Profile logic
    public function show($handle, $uuid = null)
    {
        // $uuid is ignored here as Actor Profile is just /@{handle}
        \Illuminate\Support\Facades\Log::info("ActorProfile: Accessed $handle from " . request()->ip());
        $actor = $this->findActor($handle);

        if (!$actor) {
            abort(404, 'Actor not found');
        }

        // Check for JSON request
        if (request()->wantsJson() || str_contains(request()->header('Accept'), 'application/ld+json') || str_contains(request()->header('Accept'), 'application/activity+json')) {
            $transformer = new \Ethernick\ActivityPubCore\Transformers\ActorTransformer();
            return response()->json($transformer->transform($actor))
                ->header('Content-Type', 'application/activity+json');
        }

        // Return HTML view
        // Priority:
        // 1. User template: 'actor'
        // 2. Package template: 'activitypub::actor'
        $template = \Illuminate\Support\Facades\View::exists('actor') ? 'actor' : 'activitypub::actor';

        return (new \Statamic\View\View)
            ->template($template)
            ->layout('layout')
            ->with(['actor' => $actor]);
    }

    public function collection($handle, $collection)
    {
        // 1. Find Actor
        $actor = $this->findActor($handle);

        if (!$actor) {
            abort(404, 'Actor not found');
        }

        // Handle specific collections that are relationships, not content taxonomies
        if ($collection === 'followers') {
            return $this->followersCollection($actor);
        }
        if ($collection === 'following') {
            return $this->followingCollection($actor);
        }

        // 2. Find Collection Term for content collections (outbox, etc.)
        $term = Term::query()
            ->where('taxonomy', 'activitypub_collections')
            ->where('slug', $collection)
            ->first();

        if (!$term) {
            abort(404, 'Collection not found');
        }

        // 3. Query Entries
        // Get all collections that are ActivityPub enabled
        $enabledCollections = $this->getEnabledCollections();

        $items = Entry::query()
            ->whereIn('collection', $enabledCollections)
            ->whereTaxonomy('activitypub_collections::' . $term->slug())
            ->get() // Execute query to get collection
            ->filter(function ($entry) use ($actor) {
                // Determine entry actor
                $entryActor = $entry->get('actor');
                if (is_array($entryActor)) {
                    $entryActor = $entryActor[0] ?? null;
                }

                // Compare with current actor ID
                return $entryActor === $actor->id();
            });

        // Pagination needs to be done manually on the collection now
        $page = request()->get('page', 1);
        if (!is_numeric($page) || (int) $page < 1) {
            $page = 1;
        }
        $page = (int) $page;

        $perPage = 20;


        $entries = new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // Check for JSON request
        if (request()->wantsJson() || request()->header('Accept') === 'application/ld+json') {
            return $this->respondWithCollectionJson($handle, $actor, $term, $entries);
        }

        // Return HTML view
        return (new \Statamic\View\View)
            ->template('activitypub::collection')
            ->layout('layout')
            ->with([
                    'actor' => $actor,
                    'term' => $term,
                    'entries' => $entries
                ]);
    }

    protected function followersCollection($actor)
    {
        $followers = $actor->get('followed_by_actors', []) ?: [];
        return $this->paginateAndRespondIds($actor, 'followers', collect($followers));
    }

    protected function followingCollection($actor)
    {
        $following = $actor->get('following_actors', []) ?: [];
        return $this->paginateAndRespondIds($actor, 'following', collect($following));
    }

    protected function paginateAndRespondIds($actor, $type, $ids)
    {
        $page = (int) request()->get('page', 1);
        $perPage = 20;

        // Resolve IDs to URI or objects if needed, but for followers/following list of URI is standard.
        // We might be storing internal IDs.
        $resolvedItems = $ids->map(function ($id) {
            // Check if it's a UUID (local) or URL (remote)
            if (\Illuminate\Support\Str::isUuid($id)) {
                $entry = Entry::find($id);
                if ($entry) {
                    $apId = $entry->get('activitypub_id');
                    // If local, construct ID
                    if (!$apId && $entry->get('is_internal')) {
                        return $this->sanitizeUrl(url('@' . $entry->slug()));
                    }
                    return $apId;
                }
                return null;
            }
            return $id;
        })->filter()->values();

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $resolvedItems->forPage($page, $perPage),
            $resolvedItems->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => $this->sanitizeUrl(url('@' . $actor->slug() . '/' . $type)),
            'totalItems' => $paginated->total(),
            'orderedItems' => $paginated->items(),
        ])->header('Content-Type', 'application/ld+json');
    }

    protected function getEnabledCollections()
    {
        $path = resource_path('settings/activitypub.yaml');
        if (!File::exists($path)) {
            return [];
        }
        $settings = YAML::parse(File::get($path));

        $enabled = [];
        foreach ($settings as $handle => $config) {
            if ($handle === 'activitypub_collections') {
                continue;
            }

            if (is_bool($config) && $config) {
                $enabled[] = $handle;
            } elseif (is_array($config) && ($config['enabled'] ?? false)) {
                $enabled[] = $handle;
            }
        }

        return $enabled;
    }

    protected function returnActorJson($actor)
    {
        // Basic Actor JSON
        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Person',
            'id' => $this->sanitizeUrl(url('@' . $actor->slug())), // Should be full URL
            'name' => $actor->get('title'),
            'preferredUsername' => $actor->slug(),
            'summary' => $actor->get('content'),
            'inbox' => $this->sanitizeUrl(url('@' . $actor->slug() . '/inbox')),
            'outbox' => $this->sanitizeUrl(url('@' . $actor->slug() . '/outbox')),
            'followers' => $this->sanitizeUrl(url('@' . $actor->slug() . '/followers')),
            'following' => $this->sanitizeUrl(url('@' . $actor->slug() . '/following')),
        ])->header('Content-Type', 'application/ld+json');
    }

    protected function sanitizeUrl($url)
    {
        return str_replace('://www.', '://', $url);
    }

    protected function respondWithCollectionJson($handle, $actor, $term, $entries)
    {
        $items = [];
        $isActorCollection = in_array($term->slug(), ['following', 'followers']);

        foreach ($entries as $entry) {
            if ($isActorCollection) {
                // For following/followers, we want the Actor ID (URI)
                if ($entry->collectionHandle() === 'actors') {
                    // For external actors, use activitypub_id
                    // For internal actors, construct their ID
                    $id = $entry->get('activitypub_id');
                    if (!$id && $entry->get('is_internal')) {
                        $id = $this->sanitizeUrl(url('@' . $entry->slug()));
                    }
                    if ($id)
                        $items[] = $id;
                } else {
                    // Fallback for non-actor entries if they somehow end up here
                    $items[] = $entry->absoluteUrl();
                }
            } else {
                // For content collections (outbox, articles, etc), return the object
                $json = $entry->get('activitypub_json');

                if ($json) {
                    $items[] = json_decode($json);
                }
            }
        }

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => $this->sanitizeUrl(url('@' . $handle . '/' . $term->slug())),
            'totalItems' => $entries->total(),
            'orderedItems' => $items,
        ])->header('Content-Type', 'application/ld+json');
    }

    public function inbox(Request $request, $handle)
    {


        $actor = $this->findActor($handle);
        if (!$actor) {
            \Illuminate\Support\Facades\Log::warning("ActivityPub Inbox: Actor not found for handle $handle"); // Keep standard log too
            abort(404, 'Actor not found');
        }

        if ($request->isMethod('POST')) {
            return $this->handleInboxPost($request, $actor);
        }

        // If we got here via GET, it might be a redirected POST that lost its method
        \Illuminate\Support\Facades\Log::warning("ActivityPub Inbox: Received GET request. Possible 301/302 redirect stripping POST data?");

        return response()->json(['message' => 'Inbox available. Please POST activity.'], 200);


    }

    public function sharedInbox(Request $request)
    {


        if ($request->isMethod('POST')) {
            $payload = $request->json()->all();

            // 1. Collect Recipient Handles
            $recipients = [];

            // Check 'to' and 'cc' fields
            foreach (['to', 'cc'] as $field) {
                if (isset($payload[$field])) {
                    $targets = is_array($payload[$field]) ? $payload[$field] : [$payload[$field]];
                    foreach ($targets as $target) {
                        $handle = $this->extractHandleFromId($target);
                        if ($handle) {
                            $recipients[$handle] = true;
                        }
                    }
                }
            }

            // Fallback: For Follow activities, check 'object' if no 'to' matched a local actor
            // (Standard says Follow object is the target)
            if (empty($recipients) && isset($payload['type']) && $payload['type'] === 'Follow') {
                $targetId = $payload['object'] ?? null;
                if ($targetId) {
                    $handle = $this->extractHandleFromId($targetId);
                    if ($handle) {
                        $recipients[$handle] = true;
                    }
                }
            }

            if (empty($recipients)) {
                \Illuminate\Support\Facades\Log::info("ActivityPub SharedInbox: No local recipients found", ['payload' => $payload]);
                return response()->json(['message' => 'Accepted (No recipients)'], 202);
            }

            // 2. Dispatch to each recipient
            foreach (array_keys($recipients) as $handle) {
                $actor = $this->findActor($handle);
                if ($actor) {
                    \Illuminate\Support\Facades\Log::info("ActivityPub SharedInbox: Routing {$payload['type']} to {$actor->slug()}");
                    // We call the handler directly. Note: This returns a response object.
                    // In a real shared inbox, we might queue these or just process them synchronously.
                    // For now, synchronous is fine. We capture the last response or success.
                    $this->handleInboxPost($request, $actor);
                }
            }

            return response()->json(['message' => 'Accepted'], 202);
        }

        return response()->json(['message' => 'Shared Inbox available.'], 200);
    }

    protected function extractHandleFromId($id)
    {
        // Extracts 'nick' from 'https://ethernick.com/@nick' or 'https://ethernick.com/@nick/...'
        // Crude parsing, improvements welcome
        if (preg_match('/\/@([^\/]+)/', $id, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function handleInboxPost(Request $request, $actor)
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            \Illuminate\Support\Facades\Log::warning("ActivityPub Inbox: Empty payload");
            return response()->json(['error' => 'Empty payload'], 400);
        }

        $type = $payload['type'] ?? 'Unknown';
        $actorId = $payload['actor'] ?? 'Unknown';

        \Illuminate\Support\Facades\Log::info("ActivityPub Inbox: Received $type from $actorId", ['payload' => $payload]);

        // 1. Blocklist Check
        $activityId = $payload['id'] ?? null;
        if ($activityId && \Ethernick\ActivityPubCore\Services\BlockList::isBlocked(parse_url($activityId, PHP_URL_HOST))) {
            return response()->json(['error' => 'Blocked'], 403);
        }
        if ($actorId && \Ethernick\ActivityPubCore\Services\BlockList::isBlocked(parse_url($actorId, PHP_URL_HOST))) {
            return response()->json(['error' => 'Blocked'], 403);
        }

        // 2. HTTP Signature Validation
        if (!$this->verifySignature($request)) {
            \Illuminate\Support\Facades\Log::error("ActivityPub Inbox: Invalid signature from $actorId");
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 3. Immediate Processing (Follow/Accept)
        if (in_array($type, ['Follow', 'Accept'])) {
            \Illuminate\Support\Facades\Log::info("ActivityPub Inbox: Immediate processing for $type");

            // Resolve Actor for immediate processing
            $resolver = new \Ethernick\ActivityPubCore\Services\ActorResolver();
            $externalActor = $resolver->resolve($actorId, true); // Save=true for relationships

            if (!$externalActor) {
                \Illuminate\Support\Facades\Log::error("ActivityPub Inbox: Could not resolve external actor $actorId for immediate processing");
                return response()->json(['error' => 'Could not resolve actor'], 400);
            }

            try {
                $handler = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
                $handler->handle($payload, $actor, $externalActor);
                return response()->json(['success' => true, 'processed' => true], 200);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("ActivityPub Inbox: Error processing $type immediately: " . $e->getMessage());
                return response()->json(['error' => 'Processing failed'], 500);
            }
        }

        // 4. Queue (Create, Update, Delete, Announce, Like, Undo, etc.)
        // We do NOT resolve the actor here to save time/bandwidth.
        // We trust the signature verification above.
        // The background job will resolve the actor if needed.

        $queueData = [
            'payload' => $payload,
            'local_actor_id' => $actor->id(),
            'external_actor_url' => $actorId,
            'external_actor_id' => null, // Let the job look it up or resolve it
        ];

        // Optimization: Quick check if we ALREADY have this actor in DB, 
        // passing the ID helps the job skip a lookup.
        $existingActor = Entry::query()->where('collection', 'actors')->where('activitypub_id', $actorId)->first();
        if ($existingActor) {
            $queueData['external_actor_id'] = $existingActor->id();
        }

        $queue = new \Ethernick\ActivityPubCore\Jobs\FileQueue();
        $filename = $queue->push('inbox', $queueData);

        \Illuminate\Support\Facades\Log::info("ActivityPub Inbox: Queued to $filename");

        return response()->json(['success' => true, 'queued' => true], 202);
    }


    protected function verifySignature(Request $request)
    {
        if (app()->runningUnitTests() && self::$shouldSkipSignatureVerificationInTests) {
            return true;
        }

        $verified = false;

        try {
            $server = new \ActivityPhp\Server([
                'instance' => [
                    'host' => $request->getHost(),
                    'port' => $request->getPort(),
                    'debug' => config('app.debug'),
                ],
            ]);

            $signature = new \ActivityPhp\Server\Http\HttpSignature($server);

            // The library expects a Symfony Request, which Laravel's Request extends.
            // We suppress exceptions here to allow fallback to try
            try {
                $verified = $signature->verify($request);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('ActivityPub: Library signature verification threw error: ' . $e->getMessage());
                $verified = false;
            }

            // Fallback: If library fails or errors, try manual OpenSSL verification
            if (!$verified) {
                try {
                    $sigHeader = $request->headers->get('signature');
                    if ($sigHeader) {
                        // 1. Parse Signature Header
                        if (preg_match('/keyId="(?P<keyId>.*?)",.*headers="(?P<headers>.*?)",.*signature="(?P<signature>.*?)"/', $sigHeader, $matches)) {
                            $keyId = $matches['keyId'];
                            $signedHeadersStr = $matches['headers'];
                            $signatureStr = $matches['signature'];

                            // 2. Fetch Actor Key (Robustly)
                            $pem = null;
                            try {
                                // Try library first
                                $actor = $server->actor($keyId);
                                $pem = $actor->getPublicKeyPem();
                            } catch (\Exception $e) {
                                \Illuminate\Support\Facades\Log::info("ActivityPub: Library failed to fetch key for $keyId, trying manual fetch. Error: " . $e->getMessage());

                                // Manual Fetch with relaxed SSL for dev/localhost
                                // Manual Fetch
                                try {
                                    // 1. Prepare URL (strip fragment)
                                    $fetchUrl = explode('#', $keyId)[0];
                                    $options = [];

                                    // Localhost/Dev adjustments
                                    if (app()->environment('local', 'dev', 'testing') && str_contains($keyId, 'localhost') && (str_contains($e->getMessage(), 'wrong version number') || str_contains($e->getMessage(), 'SSL'))) {
                                        $fetchUrl = str_replace('https://', 'http://', $fetchUrl);
                                        $options['verify'] = false;
                                    }

                                    // 2. Fetch with JSON-LD headers
                                    $response = \Illuminate\Support\Facades\Http::withHeaders([
                                        'Accept' => 'application/activity+json, application/ld+json',
                                    ])->withOptions($options)->get($fetchUrl);

                                    if ($response->successful()) {
                                        $data = $response->json();

                                        // 3. Extract PEM
                                        // Case A: The fetched object IS the key (has publicKeyPem directly)
                                        $pem = $data['publicKeyPem'] ?? null;

                                        // Case B: The fetched object is an Actor (has publicKey object)
                                        if (!$pem && isset($data['publicKey']) && is_array($data['publicKey'])) {
                                            $pem = $data['publicKey']['publicKeyPem'] ?? null;
                                        }

                                        if ($pem) {
                                            \Illuminate\Support\Facades\Log::info("ActivityPub: Successfully manually fetched key for $keyId");
                                        } else {
                                            \Illuminate\Support\Facades\Log::warning("ActivityPub: Manual fetch successful but no PEM found in response from $fetchUrl");
                                        }
                                    } else {
                                        \Illuminate\Support\Facades\Log::warning("ActivityPub: Manual fetch failed for $fetchUrl with status " . $response->status());
                                    }

                                } catch (\Exception $inner) {
                                    \Illuminate\Support\Facades\Log::error("ActivityPub: Manual key fetch failed: " . $inner->getMessage());
                                }
                            }

                            if ($pem) {
                                // 3. Reconstruct Plain Text dynamically based on what was signed
                                $signedHeaders = explode(' ', $signedHeadersStr);
                                $plainLines = [];

                                foreach ($signedHeaders as $headerName) {
                                    if ($headerName === '(request-target)') {
                                        $plainLines[] = sprintf(
                                            "(request-target): %s %s%s",
                                            strtolower($request->getMethod()),
                                            $request->getPathInfo(),
                                            $request->getQueryString() ? '?' . $request->getQueryString() : ''
                                        );
                                    } else {
                                        $val = $request->headers->get($headerName);
                                        if ($val !== null) {
                                            $plainLines[] = "{$headerName}: {$val}";
                                        }
                                    }
                                }

                                $plainText = implode("\n", $plainLines);

                                // 4. Verify
                                $result = openssl_verify($plainText, base64_decode($signatureStr), $pem, OPENSSL_ALGO_SHA256);
                                if ($result === 1) {
                                    \Illuminate\Support\Facades\Log::info('ActivityPub: Signature verified via OpenSSL fallback.');
                                    return true;
                                } else {
                                    while ($msg = openssl_error_string()) {
                                        \Illuminate\Support\Facades\Log::debug("ActivityPub: OpenSSL Error: $msg");
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('ActivityPub: OpenSSL Fallback verification failed: ' . $e->getMessage());
                }

                \Illuminate\Support\Facades\Log::warning('ActivityPub: Signature validation failed (library and fallback). Ignoring request.');
            }

            return $verified;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Signature validation process failed: ' . $e->getMessage());
            // In dev mode, maybe we want to leniency? For now, keep secure.
            if (app()->runningUnitTests()) {
                return true;
            }

            throw $e;
        }
    }

}
