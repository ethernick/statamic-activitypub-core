<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Http\Controllers\ActorController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActorControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        ActorController::$shouldSkipSignatureVerificationInTests = true;
        parent::tearDown();
    }

    #[Test]
    public function it_falls_back_to_manual_key_fetch_when_library_fails()
    {
        // 0. Setup Local Actor
        // Ensure collection exists
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        $localActor = \Statamic\Facades\Entry::make()
            ->collection('actors')
            ->slug('localuser')
            ->data(['title' => 'Local User', 'is_internal' => true])
            ->id('localuser-id');
        $localActor->save();

        // 1. Setup keys
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];

        // 2. Prepare Request Data
        $actorId = 'https://example.com/users/alice';
        $keyId = $actorId . '#main-key';
        $payload = [
            'type' => 'Note',
            'actor' => $actorId,
            'content' => 'Hello World',
            'to' => ['https://www.w3.org/ns/activitystreams#Public']
        ];

        // 3. Generate Valid HTTP Signature
        // The headers usually signed are (request-target), host, date.
        // We use the personal inbox which forces signature check.
        $target = 'post /@localuser/inbox';
        $host = 'statamic.ether'; // Default for test
        $date = gmdate('D, d M Y H:i:s T');

        $signingString = "(request-target): $target\nhost: $host\ndate: $date";
        openssl_sign($signingString, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureBase64 = base64_encode($signature);

        $signatureHeader = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date",signature="%s"',
            $keyId,
            $signatureBase64
        );

        // 4. Mock HTTP Facade for the Manual Fetch
        // verifySignature logic: Library fails -> Catch -> Manual Fetch
        Http::fake([
            'example.com/*' => Http::response([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $actorId,
                'type' => 'Person',
                'publicKey' => [
                    'id' => $keyId,
                    'owner' => $actorId,
                    'publicKeyPem' => $publicKey
                ]
            ], 200),
        ]);

        // 5. Force Verification Logic
        ActorController::$shouldSkipSignatureVerificationInTests = false;

        // 6. Make Request
        $response = $this->postJson('/@localuser/inbox', $payload, [
            'Signature' => $signatureHeader,
            'Date' => $date,
            'Host' => $host,
        ]);

        // 7. Assertions
        // If verification passed, it should return 202 (Accepted) or success.
        // If it failed, it returns 401.
        $response->assertStatus(202);

        // Ensure manual fetch was called
        Http::assertSent(function ($request) use ($actorId) {
            return $request->url() === $actorId &&
                str_contains($request->header('Accept')[0], 'application/activity+json');
        });
    }
}
