<?php

namespace App\Support\Workspaces;

use RuntimeException;

/**
 * A workspace change that was refused for a reason worth telling the person —
 * most often that leaving would leave a company without an HR Manager. The
 * message is written to be shown as it is.
 */
class WorkspaceException extends RuntimeException {}
