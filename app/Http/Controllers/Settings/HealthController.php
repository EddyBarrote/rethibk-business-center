<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Health\HealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições › Estado da configuração (docs/DECISOES.md, "Verificação da
 * configuração"): owners and admins see whether everything the agents depend
 * on works, and test it again.
 */
class HealthController extends Controller
{
    public function show(Request $request, HealthCheck $health): Response
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        return Inertia::render('Settings/Health', ['checks' => $health->all($user)]);
    }

    public function check(Request $request, HealthCheck $health): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        $health->run($user);
        AuditLog::record($user, 'platform.health_checked', []);

        return back()->with('success', 'Verificado agora.');
    }
}
