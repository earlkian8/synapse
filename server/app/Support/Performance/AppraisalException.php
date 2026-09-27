<?php

namespace App\Support\Performance;

use RuntimeException;

/**
 * An appraisal action that was refused for a reason worth telling the person —
 * the cycle is closed, the scorecard is already submitted, a rating is off its
 * scale. The message is written to be shown as it is, on the screens and in the
 * assistant alike.
 */
class AppraisalException extends RuntimeException {}
