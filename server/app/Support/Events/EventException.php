<?php

namespace App\Support\Events;

use RuntimeException;

/**
 * An event action that was refused for a reason worth telling the person —
 * everyone is already invited, the event is over, nobody is left to remind, the
 * room is taken. The message is written to be shown as it is, on the screens and
 * in the assistant alike.
 *
 * `$field` names the form field a refusal belongs to (`room_id`, `repeat`), so a
 * screen can show it beside that field and keep the form open.
 */
class EventException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
