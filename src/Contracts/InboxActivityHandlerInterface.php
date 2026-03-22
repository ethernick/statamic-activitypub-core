<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Contracts;

interface InboxActivityHandlerInterface
{
    /**
     * Handle an incoming activity for a specific object type.
     *
     * @param array $payload The full activity payload.
     * @param array $object The object within the activity.
     * @param mixed $localActor The local actor the activity is addressed to.
     * @param mixed $externalActor The external actor who sent the activity.
     * @return bool Whether the activity should be saved to the database.
     */
    public function handle(array $payload, array $object, mixed $localActor, mixed $externalActor): bool;
}
