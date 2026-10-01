<?php

namespace App\Support\Recruitment;

use RuntimeException;

/**
 * A pipeline change that was refused for a reason worth telling the person — a
 * stage candidates still sit on, a pipeline postings still run on, the default
 * left unset. The message is written to be shown as it is, on the screen and in
 * the assistant alike.
 */
class PipelineException extends RuntimeException {}
