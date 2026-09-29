<?php

namespace App\Support\Users;

use RuntimeException;

/**
 * An account change that was refused for a reason worth telling the person —
 * their own account, an account that belongs to another workspace too, an
 * account with more access than theirs, an email already here. The message is
 * written to be shown as it is, on the screen and in the assistant alike.
 */
class UserAccountException extends RuntimeException {}
