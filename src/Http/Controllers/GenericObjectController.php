<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface;

class GenericObjectController extends BaseObjectController implements ActivityHandlerInterface
{
    protected static array $handledActivityTypes = [
        'Create:Object',
        'Update:Object',
        'Delete:Object',
    ];
}
