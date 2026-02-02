<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Types\Concerns;

trait SilencesStrictValidation
{
    /**
     * Override the default strict set() method.
     * If a property is not defined, we simply ignore it instead of throwing an exception,
     * effectively enforcing 'ignore' mode regardless of global config.
     */
    public function set($name, $value)
    {
        // If the property exists (natively or in _props), parent::set works fine.
        if ($this->has($name)) {
            return parent::set($name, $value);
        }

        // If it doesn't exist, we ignore it to prevent crashes.
        // (We can't easily "include" it because _props is private in the parent class)
        return $this;
    }
}
