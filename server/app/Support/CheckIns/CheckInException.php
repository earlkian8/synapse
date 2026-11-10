<?php

namespace App\Support\CheckIns;

use RuntimeException;

/**
 * A check-in refused for a reason worth telling the person — check-ins are off
 * here, their account isn't linked to an employee record. Written to be shown as it
 * is, on the web and in the app alike.
 */
class CheckInException extends RuntimeException {}
