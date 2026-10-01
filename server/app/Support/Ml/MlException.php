<?php

namespace App\Support\Ml;

use RuntimeException;

/**
 * Raised when the ML inference service (FastAPI) cannot fulfil a request —
 * unreachable, timed out, or a non-2xx response. Callers catch it to degrade
 * gracefully (e.g. record a failed assessment run and surface a friendly toast)
 * rather than 500-ing the page.
 */
class MlException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $unreachable = false,
    ) {
        parent::__construct($message);
    }
}
