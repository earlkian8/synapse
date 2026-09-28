<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * A work-location change that was refused for a reason worth telling the
 * person — a site punches name cannot be permanently deleted. The message is
 * written to be shown as it is, on the screen and in the assistant alike.
 */
class WorkLocationException extends RuntimeException {}
