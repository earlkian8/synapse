<?php

namespace App\Support\Training;

use RuntimeException;

/**
 * A training action that was refused for a reason worth telling the person —
 * the program is full, a session falls outside the program's dates, attendance
 * is taken before the day, the program has started so leaving needs HR. The
 * message is written to be shown as it is, on the screens and in the assistant
 * alike.
 *
 * `$field` names the form field a refusal belongs to (`session_date`,
 * `repeat_until`), so a screen can show it beside that field and keep the form
 * open.
 */
class TrainingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
