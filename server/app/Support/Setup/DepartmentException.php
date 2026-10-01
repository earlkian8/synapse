<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * An org-structure change that was refused for a reason worth telling the person
 * — a restored department's code is taken. The message is written to be shown as
 * it is, on the screens and in the assistant alike.
 */
class DepartmentException extends RuntimeException {}
