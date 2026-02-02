<?php

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;

class OutboxController extends CpController
{
    public function index()
    {
        return view('activitypub::stubs', ['title' => 'Outbox']);
    }
}
