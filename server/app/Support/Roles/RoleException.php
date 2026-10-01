<?php

namespace App\Support\Roles;

use RuntimeException;

/**
 * A role or role-assignment change that was refused for a reason worth telling
 * the person — access they would grant but do not hold, the HR Manager role
 * in the hands of someone who is not one, the workspace's last HR Manager, a
 * built-in role. The message is written to be shown as it is, on the screen
 * and in the assistant alike.
 */
class RoleException extends RuntimeException {}
