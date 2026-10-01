<?php

namespace App\Support\Ml\Graduation;

use RuntimeException;

/**
 * A graduation step that cannot happen in the current state — training while a
 * requirement is unmet, switching to a model that did not pass its check. The
 * message is shown to the person who clicked, so it says what to do instead.
 */
class GraduationException extends RuntimeException {}
