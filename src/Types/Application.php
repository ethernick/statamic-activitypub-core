<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Types;

use ActivityPhp\Type\Extended\Actor\Application as BaseApplication;

class Application extends BaseApplication
{
    use \Ethernick\ActivityPubCore\Types\Concerns\SilencesStrictValidation;

    protected $implements;
}
