<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Handlers;

use Ethernick\ActivityPubCore\Contracts\OutboxHandlerInterface;
use Statamic\Entries\Entry;

class NoteOutboxHandler implements OutboxHandlerInterface
{
    public function format(array $data, Entry $entry): array
    {
        // For notes, we don't currently have extra outbox fields beyond common ones,
        // but this lives here to follow the modular pattern.
        return $data;
    }
}
