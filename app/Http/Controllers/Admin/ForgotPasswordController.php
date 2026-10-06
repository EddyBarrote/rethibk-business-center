<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\ForgotPasswordController as TenantForgotPasswordController;

/**
 * Password reset link for Rethink operators, on the admin host.
 */
class ForgotPasswordController extends TenantForgotPasswordController
{
    protected string $broker = 'platform_admins';

    protected function brand(): ?array
    {
        return null;
    }
}
