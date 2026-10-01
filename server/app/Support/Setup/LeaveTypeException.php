<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * A leave-type change that was refused for a reason worth telling the person —
 * a restored type's code is taken, a type with requests cannot be permanently
 * deleted. The message is written to be shown as it is, on the screen and in
 * the assistant alike.
 */
class LeaveTypeException extends RuntimeException {}
