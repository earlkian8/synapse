<?php

namespace App\Support\Offboarding;

use RuntimeException;

/**
 * An offboarding action that was refused for a reason worth telling the person —
 * the employee is already exiting, the exit is already closed, nothing was left
 * to sign off. The message is written to be shown as it is, on the screens and
 * in the assistant alike.
 */
class OffboardingException extends RuntimeException {}
