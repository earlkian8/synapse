<?php

namespace App\Support\DataExport;

use RuntimeException;

/**
 * A Data Export action that cannot go ahead, with a message fit to show the
 * person who tried it.
 */
class DataExportException extends RuntimeException {}
