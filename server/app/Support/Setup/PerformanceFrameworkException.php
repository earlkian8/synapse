<?php

namespace App\Support\Setup;

use RuntimeException;

/**
 * A performance-framework change that was refused for a reason worth telling
 * the person — a framework, scale, criterion or review cycle still in use cannot
 * be permanently deleted. The message is written to be shown as it is, on the
 * screen and in the assistant alike.
 */
class PerformanceFrameworkException extends RuntimeException {}
