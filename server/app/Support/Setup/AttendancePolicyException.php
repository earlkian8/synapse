<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * An attendance-policy change that was refused for a reason worth telling the
 * person — a policy something still names cannot be permanently deleted, and
 * one whose name was taken while it was archived cannot be restored. The
 * message is written to be shown as it is, on the screen and in the assistant
 * alike.
 */
class AttendancePolicyException extends RuntimeException {}
