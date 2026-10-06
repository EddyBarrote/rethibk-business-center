<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Capability;
use App\Models\Connector;
use App\Models\ErpConnection;
use App\Models\PlatformConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integrações: the ERP connection and the connectors that link agents to
 * other systems, in one list. The ERP is for those who administer the
 * company; connectors for those who manage the catalogue.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $erp = $user->canManageTenant();
        $catalog = Gate::allows('manage-catalog');
        abort_unless($erp || $catalog, 403);

        $connection = $erp ? ErpConnection::query()->oldest('id')->first() : null;
        $activeGlobal = $catalog
            ? Capability::query()->where('is_enabled', true)->whereNotNull('platform_connector_id')->pluck('platform_connector_id')->unique()
            : collect();

        return Inertia::render('Settings/Integrations', [
            'can_erp' => $erp,
            'can_connectors' => $catalog,
            'erp' => $connection === null ? null : [
                'name' => $connection->name,
                'base_url' => $connection->base_url,
                'status' => $connection->status->value,
                'status_label' => $connection->status->label(),
                'last_checked_at' => $connection->last_checked_at?->toIso8601String(),
                'tools' => count($connection->capabilities ?? []),
            ],
            'connectors' => $catalog ? Connector::query()->orderBy('name')->get()->map(fn (Connector $connector) => $connector->summary()) : [],
            'globalConnectors' => $catalog ? PlatformConnector::query()->where('is_active', true)->orderBy('name')->get()->map(fn (PlatformConnector $connector) => [
                ...collect($connector->summary())->except(['url', 'has_secret', 'last_error', 'input_schema'])->all(),
                'activated' => $activeGlobal->contains($connector->id),
            ]) : [],
        ]);
    }
}
