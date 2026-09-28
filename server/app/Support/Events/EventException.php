<?php

namespace App\Support\Events;

use RuntimeException;

/**
 * An event action that was refused for a reason worth telling the person —
 * everyone is already invited, the event is over, nobody is left to remind.
 * The message is written to be shown as it is, on the screens and in the
 * assistant alike.
 */
class EventException extends RuntimeException {}
