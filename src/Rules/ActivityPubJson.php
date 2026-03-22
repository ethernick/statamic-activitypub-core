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

        if (!is_string($json)) {
            $this->errorMessage = "The :attribute must be a string or a valid code object.";
            return false;
        }

        if (empty($json)) {
            return true;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errorMessage = "Invalid JSON: " . json_last_error_msg();
            return false;
        }

        // Semantic Check
        if (!isset($decoded['@context'])) {
            $this->errorMessage = "ActivityPub Warning: Missing '@context' attribute.";
            return false;
        }
        if (!isset($decoded['type'])) {
            $this->errorMessage = "ActivityPub Warning: Missing 'type' attribute.";
            return false;
        }

        return true;
    }

    public function message()
    {
        return $this->errorMessage;
    }
}
