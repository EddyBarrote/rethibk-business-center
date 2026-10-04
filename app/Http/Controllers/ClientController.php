<?php

namespace App\Http\Controllers;

use App\Clients\ClientSheetBuilder;
use App\Console\Concerns\DispatchesRoles;
use App\Enums\TriggerType;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Insights\SlaMonitor;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients (E08): search in the ERP, the live sheet, and a pre-meeting brief
 * on request.
 */
class ClientController extends Controller
{
    use DispatchesRoles;

    public function index(Request $request, ErpGateway $erp, SlaMonitor $sla): Response
    {
        $user = $this->manager($request);
        $query = trim((string) $request->query('q', ''));
        $accounts = [];
        $error = null;

        try {
            $result = $erp->call('crm.search_accounts', $query !== '' ? ['query' => $query] : [], $user);
            $accounts = $result->ok ? array_values((array) ($result->data['accounts'] ?? [])) : [];
            $error = $result->ok ? null : $result->error();
        } catch (ErpException $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('Clients/Index', [
            'accounts' => $accounts,
            'q' => $query,
            'error' => $error,
            'pending' => $sla->pending(),
        ]);
    }

    public function show(Request $request, string $account, ClientSheetBuilder $sheets): Response
    {
        $user = $this->manager($request);

        try {
            $sheet = $sheets->build($account, $user);
        } catch (ErpException $e) {
            abort(404, $e->getMessage());
        }

        return Inertia::render('Clients/Show', [
            'sheet' => $sheet,
            'accountId' => $account,
            'briefs' => Report::query()->where('subject_ref', $account)->latest('id')->limit(10)->get(['id', 'title', 'type', 'created_at']),
        ]);
    }

    public function brief(Request $request, string $account): RedirectResponse
    {
        $user = $this->manager($request);
        $data = $request->validate(['meeting' => ['nullable', 'string', 'max:500']]);

        $run = $this->dispatchRole('client_manager', "Prepara o briefing para uma reunião com o cliente {$account}".(filled($data['meeting'] ?? null) ? " ({$data['meeting']})" : '').". Usa clients.sheet e escreve-o com reports.draft (tipo meeting_brief, subject_ref {$account}). Notifica {$user->email}.", trigger: TriggerType::Manual);

        return $run === null
            ? back()->with('error', 'Não há um gestor de clientes activo.')
            : to_route('runs.show', $run)->with('success', 'O gestor de clientes está a preparar o briefing.');
    }

    private function manager(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user->isManager(), 403);

        return $user;
    }
}
