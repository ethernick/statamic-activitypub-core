<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Contracts;

use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry;

interface StoreHandlerInterface
{
    /**
     * Handle the storage of a new object from the CP.
     *
     * @param Request $request
     * @return Entry
     */
    public function store(Request $request): Entry;
}
