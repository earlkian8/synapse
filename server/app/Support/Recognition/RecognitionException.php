<?php

namespace App\Support\Recognition;

use RuntimeException;

/**
 * A recognition action refused for a reason worth telling the person — you
 * can't nominate yourself, the reward is out of stock, there aren't enough
 * points. Written to be shown as it is, on the screens, in the app and in the
 * assistant alike.
 *
 * `$field` names the form field a refusal belongs to, so a screen can show it
 * beside that field.
 */
class RecognitionException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
