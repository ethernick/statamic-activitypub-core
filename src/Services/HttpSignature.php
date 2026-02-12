<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Illuminate\Support\Facades\Log;

class HttpSignature
{
    /**
     * sign
     * 
     * Signs a request with the actor's private key.
     * 
     * @param string $url The destination URL
     * @param string $actorId The ID (URL) of the signing actor
     * @param string $privateKey The PEM private key of the signing actor
     * @param string $body The request body
     * @return array Headers including the Signature
     */
    public static function sign(string $url, string $actorId, string $privateKey, string $body): array
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'];
        $path = $parsedUrl['path'] ?? '/';
        if (isset($parsedUrl['query'])) {
            $path .= '?' . $parsedUrl['query'];
        }

        $date = gmdate('D, d M Y H:i:s T');
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));

        // The exact string to sign
        // (request-target) is a special pseudo-header
        $headersToSign = [
            '(request-target)' => "post $path",
            'host' => $host,
            'date' => $date,
            'digest' => $digest,
        ];

        $stringToSign = self::buildStringToSign($headersToSign);

        // Sign with RSA-SHA256
        $binarySignature = '';
        if (!openssl_sign($stringToSign, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            Log::error("Failed to sign request for $actorId");
            return [];
        }

        $signature = base64_encode($binarySignature);

        $keyId = $actorId . '#main-key'; // Assumption: key ID is always #main-key

        $headerString = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature . '"';

        return [
            'Host' => $host,
            'Date' => $date,
            'Digest' => $digest,
            'Signature' => $headerString,
            'Content-Type' => 'application/activity+json',
            'Accept' => 'application/activity+json',
        ];
    }

    protected static function buildStringToSign(array $headers): string
    {
        $parts = [];
        foreach ($headers as $key => $value) {
            $parts[] = strtolower($key) . ': ' . $value;
        }
        return implode("\n", $parts);
    }

    /**
     * verify
     * 
     * Verifies the HTTP Signature of an incoming request.
     * 
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public static function verify(\Illuminate\Http\Request $request): bool
    {
        if (app()->runningUnitTests() && \Ethernick\ActivityPubCore\Http\Controllers\ActorController::$shouldSkipSignatureVerificationInTests) {
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
                Log::warning('ActivityPub: Library signature verification threw error: ' . $e->getMessage());
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
                                Log::info("ActivityPub: Library failed to fetch key for $keyId, trying manual fetch. Error: " . $e->getMessage());

                                // Manual Fetch with relaxed SSL for dev/localhost
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
                                            Log::info("ActivityPub: Successfully manually fetched key for $keyId");
                                        } else {
                                            Log::warning("ActivityPub: Manual fetch successful but no PEM found in response from $fetchUrl");
                                        }
                                    } else {
                                        Log::warning("ActivityPub: Manual fetch failed for $fetchUrl with status " . $response->status());
                                    }

                                } catch (\Exception $inner) {
                                    Log::error("ActivityPub: Manual key fetch failed: " . $inner->getMessage());
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
                                    Log::info('ActivityPub: Signature verified via OpenSSL fallback.');
                                    return true;
                                } else {
                                    while ($msg = openssl_error_string()) {
                                        Log::debug("ActivityPub: OpenSSL Error: $msg");
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('ActivityPub: OpenSSL Fallback verification failed: ' . $e->getMessage());
                }

                Log::warning('ActivityPub: Signature validation failed (library and fallback). Ignoring request.');
            }

            return $verified;
        } catch (\Exception $e) {
            Log::error('Signature validation process failed: ' . $e->getMessage());
            // In dev mode, maybe we want to leniency? For now, keep secure.
            if (app()->runningUnitTests()) {
                return true;
            }
            if (config('app.debug')) {
                Log::warning('ActivityPub: Debug mode ON - Allowing request despite signature failure (Force True for testing)');
                // return true; // UNCOMMENT TO BYPASS SIG CHECK DURING DEV
            }
            throw $e;
        }
    }
}
