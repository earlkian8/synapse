<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * A schedule change that was refused for a reason worth telling the person — a
 * schedule people are assigned to cannot be permanently deleted. The message is
 * written to be shown as it is, on the screen and in the assistant alike.
 */
class WorkScheduleException extends RuntimeException {}
