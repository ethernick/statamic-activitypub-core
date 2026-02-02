<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

abstract class BaseActivityController extends BaseController
{
    /**
     * Common logic for Activities (which are Objects).
     * If validation or structure differs, override methods here.
     */

    protected function findItem($uuid)
    {
        // Activities are stored in 'activities' collection usually
        return \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('id', $uuid)
            ->first();
    }

    protected function verifyRequestSignature(\Illuminate\Http\Request $request)
    {
        return \Ethernick\ActivityPubCore\Services\HttpSignature::verify($request);
    }
}
