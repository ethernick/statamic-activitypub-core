<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

abstract class BaseActivityController extends BaseController
{
    /**
     * Common logic for Activities (which are Objects).
     * If validation or structure differs, override methods here.
     */

    protected function findItem(string $uuid): ?\Statamic\Contracts\Entries\Entry
    {
        // Activities are stored in 'activities' collection usually
        return \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('id', $uuid)
            ->first();
    }

    protected function verifyRequestSignature(\Illuminate\Http\Request $request): bool
    {
        return \Ethernick\ActivityPubCore\Services\HttpSignature::verify($request);
    }
}
