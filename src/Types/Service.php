<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Types;

use ActivityPhp\Type\Extended\Actor\Service as BaseService;

class Service extends BaseService
{
    use \Ethernick\ActivityPubCore\Types\Concerns\SilencesStrictValidation;

    /**
     * @see https://docs.joinmastodon.org/spec/activitypub/#manuallyApprovesFollowers
     */
    protected $manuallyApprovesFollowers;

    protected $discoverable;
    protected $featured;
    protected $alsoKnownAs;
    protected $attributionDomains;
    protected $featuredTags;
}
