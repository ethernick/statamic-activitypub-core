<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Events;

use Statamic\Entries\Entry;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class EntryCleaning
{
    use Dispatchable, SerializesModels;

    public Entry $entry;

    /**
     * Create a new event instance.
     *
     * @param Entry $entry
     */
    public function __construct(Entry $entry)
    {
        $this->entry = $entry;
    }
}
