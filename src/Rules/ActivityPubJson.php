<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Rules;

use Illuminate\Contracts\Validation\Rule;

class ActivityPubJson implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected $errorMessage = 'The :attribute must be a valid ActivityPub JSON string.';

    public function passes($attribute, $value)
    {
        $json = $value;
        if (is_array($value) && isset($value['code'])) {
            $json = $value['code'];
        }

        // If it's not a string, assume it's either null or already decoded and let it pass.
        // We only want to validate if the user is providing a JSON string.
        if (!is_string($json)) {
            return true;
        }

        if (empty($json)) {
            return true;
        }

        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errorMessage = "Invalid JSON: " . json_last_error_msg();
            return false;
        }

        return true;
    }

    public function message()
    {
        return $this->errorMessage;
    }
}
