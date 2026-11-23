<?php

namespace App\Http\Controllers\Settings;

use App\Audit\ConfidentialEntries;
use App\Enums\AuditResult;
use App\Enums\ErpConnectionStatus;
use App\Enums\ErpTransport;
use App\Erp\ErpGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ErpConnectionRequest;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\ErpConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One ERP connection per tenant (section 5.8): configure it, test it, and see
 * the audited calls on their own page.
 */
class ErpConnectionController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('viewAny', ErpConnection::class);

        $connection = $this->connection();

        return Inertia::render('Settings/Erp', [
            'connection' => $connection === null ? null : [
                'name' => $connection->name,
                'transport' => $connection->transport->value,
                'base_url' => $connection->base_url,
                'has_token' => $connection->hasToken(),
                'enabled' => $connection->status !== ErpConnectionStatus::Disabled,
                'status' => $connection->status->value,
                'status_label' => $connection->status->label(),
                'last_checked_at' => $connection->last_checked_at?->toIso8601String(),
                'last_error' => $connection->last_error,
                'capabilities' => $connection->capabilities ?? [],
            ],
            'defaults' => ['transport' => config('erp.transport')],
            'activity' => [
                'today' => AuditLog::query()->where('action', 'like', 'erp.%')->where('created_at', '>=', today())->count(),
                'errors_today' => AuditLog::query()->where('action', 'like', 'erp.%')->where('created_at', '>=', today())->where('result', AuditResult::Error)->count(),
            ],
        ]);
    }

    /**
     * Every audited operation on the ERP, newest first.
     */
    public function activity(Request $request): Response
    {
        Gate::authorize('viewAny', ErpConnection::class);

        $result = AuditResult::tryFrom((string) $request->query('result'));

        // Calls name the tool as Capacidades does; the key stays in a tooltip.
        $names = Capability::query()->where('key', 'like', 'erp.%')->pluck('name', 'key');

        $page = AuditLog::query()
            ->where('action', 'like', 'erp.%')
            ->when($result !== null, fn ($query) => $query->where('result', $result))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // An error of a call with pay and personal data may quote it (docs/DECISOES.md, "Auditoria pesquisável e exportável").
        $hidden = ConfidentialEntries::readBy($this->user($request)) ? []
            : ConfidentialEntries::forCurrentTenant()->among($page->getCollection()->map(fn (AuditLog $log) => $log->id)->values()->all());

        $calls = $page->through(fn (AuditLog $log) => [
            'id' => $log->id,
            // An approval request is audited under the capability key ("erp.expenses.classify").
            'action' => $names->has($log->action) ? 'erp.approval_requested' : $log->action,
            'tool' => $log->payload['tool'] ?? ($names->has($log->action) ? substr($log->action, 4) : null),
            'tool_name' => $names->get($names->has($log->action) ? $log->action : 'erp.'.($log->payload['tool'] ?? '')),
            'actor_type' => $log->actor_type->value,
            'result' => $log->result->value,
            'duration_ms' => $log->payload['duration_ms'] ?? null,
            'error' => isset($hidden[$log->id]) ? null : ($log->payload['error'] ?? null),
            'created_at' => $log->created_at->toIso8601String(),
        ]);

        return Inertia::render('Settings/ErpActivity', [
            'calls' => $calls,
            'filters' => ['result' => $result?->value],
        ]);
    }

    /**
     * The connection agents use; a tenant with only a disabled one sees that.
     */
    private function connection(): ?ErpConnection
    {
        return ErpConnection::query()->currentTenant()->first() ?? ErpConnection::query()->oldest('id')->first();
    }

    public function update(ErpConnectionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $connection = $this->connection() ?? new ErpConnection;
        $local = $data['transport'] === ErpTransport::Local->value;
        $endpointChanged = $connection->exists && ($connection->transport->value !== $data['transport'] || $connection->base_url !== ($local ? null : $data['base_url']));

        $connection->fill([
            'name' => $data['name'],
            'transport' => $data['transport'],
            'base_url' => $local ? null : $data['base_url'],
            'auth_type' => 'token',
        ]);

        if (filled($data['token'] ?? null)) {
            $connection->credentials = ['token' => $data['token']];
        } elseif ($local) {
            $connection->credentials = null;
        }

        $connection->status = match (true) {
            ! $data['enabled'] => ErpConnectionStatus::Disabled,
            ! $connection->exists, $endpointChanged, $connection->isDirty('credentials'), $connection->getOriginal('status') === ErpConnectionStatus::Disabled => ErpConnectionStatus::Untested,
            default => $connection->status,
        };

        $connection->save();

        AuditLog::record($request->user(), 'erp.connection_updated', [
            'transport' => $connection->transport->value,
            'base_url' => $connection->base_url,
            'token_changed' => filled($data['token'] ?? null),
            'status' => $connection->status->value,
        ], AuditResult::Ok, $connection);

        return back()->with('success', __('Ligação ao ERP guardada.'));
    }

    public function test(Request $request, ErpGateway $gateway): RedirectResponse
    {
        Gate::authorize('viewAny', ErpConnection::class);

        $connection = $this->connection() ?? abort(404);

        Gate::authorize('update', $connection);

        $connection = $gateway->test($connection, $request->user());

        return $connection->status === ErpConnectionStatus::Ok
            ? back()->with('success', __('Ligação ao ERP a funcionar: :count ferramentas disponíveis.', ['count' => count($connection->capabilities ?? [])]))
            : back()->with('error', __('A ligação ao ERP falhou: :error', ['error' => $connection->last_error]));
    }
}
