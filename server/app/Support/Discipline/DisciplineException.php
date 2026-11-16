<?php

namespace App\Support\Discipline;

use RuntimeException;

/**
 * A step of a disciplinary case that due process, or the case as it stands,
 * doesn't allow yet (ADR 0105). The message says what has to happen first.
 */
class DisciplineException extends RuntimeException {}
