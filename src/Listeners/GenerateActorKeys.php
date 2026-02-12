<?php


namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Entries\Entry;

class GenerateActorKeys
{
    public function handle(EntrySaving $event): void
    {
        /** @var Entry $entry */
        $entry = $event->entry;

        if ($entry->collectionHandle() !== 'actors') {
            return;
        }

        if ($entry->get('public_key') && $entry->get('private_key')) {
            return;
        }

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];

        $entry->set('public_key', $publicKey);
        $entry->set('private_key', $privateKey);
    }
}
