<?php

namespace App\Support\Awards;

use RuntimeException;

/**
 * A recognition that was refused for a reason worth telling the person — the
 * award type is no longer given out, the date is in the future. The message is
 * written to be shown as it is, on the screens and in the assistant alike.
 */
class AwardException extends RuntimeException {}
