<?php

namespace App\Support\Recruitment\Skills\Esco;

use RuntimeException;

/**
 * ESCO couldn't answer (ADR 0100): unreachable, too slow, an error, or an
 * answer that isn't ESCO's JSON. Callers keep what they already know and ask
 * again later; nobody is shown this.
 */
class EscoUnavailable extends RuntimeException {}
