<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;

/**
 * Editing an admin account: the same fields as creating one, the account's own
 * email excepted from the uniqueness check.
 */
class UpdateAdminRequest extends StoreAdminRequest
{
    protected function ignoredId(): ?int
    {
        $admin = $this->route('admin');

        return $admin instanceof Admin ? $admin->id : null;
    }
}
