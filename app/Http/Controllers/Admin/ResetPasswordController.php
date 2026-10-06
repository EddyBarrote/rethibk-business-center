<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\ResetPasswordController as TenantResetPasswordController;

/**
 * New password from the emailed link, for Rethink operators.
 */
class ResetPasswordController extends TenantResetPasswordController
{
    protected string $broker = 'platform_admins';

    protected string $loginRoute = 'admin.login';
}
