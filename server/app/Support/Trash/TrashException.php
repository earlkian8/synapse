<?php

namespace App\Support\Trash;

use RuntimeException;

/**
 * A restore or permanent delete that was refused for a reason worth telling
 * the person — the record's own rules said no (an account that is not this
 * workspace's to delete, one with more access than theirs). The message is
 * written to be shown as it is, on the screen and in the assistant alike.
 */
class TrashException extends RuntimeException {}
