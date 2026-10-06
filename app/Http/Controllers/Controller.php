<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in tenant user.
     */
    protected function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
