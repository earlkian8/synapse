<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * An award-type change that was refused for a reason worth telling the person —
 * a type that has been given out cannot be permanently deleted. The message is
 * written to be shown as it is, on the screen and in the assistant alike.
 */
class AwardTypeException extends RuntimeException {}
