<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\Request;

abstract class AdminController extends Controller
{
    protected function admin(Request $request): PlatformAdmin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        return $admin;
    }
}
