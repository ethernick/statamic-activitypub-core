<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Types;

use ActivityPhp\Type\Core\ObjectType;

class PropertyValue extends ObjectType
{
    protected $name;
    protected $value;
}
