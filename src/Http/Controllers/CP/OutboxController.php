<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;

class OutboxController extends CpController
{
    public function index(): mixed
    {
        return view('activitypub::stubs', ['title' => 'Outbox']);
    }
}
