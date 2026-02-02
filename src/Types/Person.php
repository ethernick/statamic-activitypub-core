<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Types;

use ActivityPhp\Type\Extended\Actor\Person as BasePerson;

class Person extends BasePerson
{
    use \Ethernick\ActivityPubCore\Types\Concerns\SilencesStrictValidation;

    /**
     * @see https://docs.joinmastodon.org/spec/activitypub/#manuallyApprovesFollowers
     */
    protected $manuallyApprovesFollowers;

    protected $discoverable;
    protected $featured;
    protected $alsoKnownAs;
    // attributionDomains and featuredTags are no longer strictly needed 
    // because the trait will ignore them, but keeping them documents support.
    protected $attributionDomains;
    protected $featuredTags;
}
