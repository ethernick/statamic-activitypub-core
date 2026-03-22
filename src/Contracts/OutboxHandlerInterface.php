<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Contracts;

use Statamic\Entries\Entry;

interface OutboxHandlerInterface
{
    /**
     * Enrich the ActivityPub data array with type-specific fields.
     *
     * @param array $data The current payload data.
     * @param Entry $entry The Statamic entry.
     * @return array The enriched payload data.
     */
    public function format(array $data, Entry $entry): array;
}
