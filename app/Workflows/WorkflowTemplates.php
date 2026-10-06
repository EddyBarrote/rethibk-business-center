<?php

namespace App\Workflows;

use App\Ai\Runs\AgentDirectory;
use App\Enums\EmailCategory;
use App\Enums\WorkflowStatus;
use App\Models\Workflow;

/**
 * Example flows the platform installs (docs/DECISOES.md, "Fluxos de
 * trabalho"). The first answers a client's request for a quotation with the
 * Gestor de Clientes, asking suppliers for prices through Procurement.
 */
final class WorkflowTemplates
{
    public function __construct(private readonly AgentDirectory $agents) {}

    /**
     * Creates the example flows that are missing, active when nothing else
     * handles their kind of email. Returns the flows created.
     *
     * @return list<Workflow>
     */
    public function install(): array
    {
        $created = [];
        $manager = $this->agents->forRole('client_manager');

        if ($manager !== null && Workflow::query()->where('email_category', EmailCategory::ClientRfq)->doesntExist()) {
            $created[] = Workflow::query()->create([
                'name' => 'Responder a pedido de cotação',
                'description' => 'Um cliente ou entidade pede-nos preço para obras, fornecimentos ou serviços; costuma trazer caderno de encargos ou lista de quantidades.',
                'email_category' => EmailCategory::ClientRfq,
                'agent_id' => $manager->id,
                'fallback_user_id' => $manager->reports_to_user_id,
                'graph' => $this->rfq($this->agents->forRole('procurement')?->id),
                'status' => WorkflowStatus::Active,
            ]);
        }

        return $created;
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function rfq(?int $procurementId): array
    {
        $node = fn (string $id, string $type, int $x, int $y, array $data, ?string $parent = null) => array_filter([
            'id' => $id,
            'type' => $type,
            'position' => ['x' => $x, 'y' => $y],
            'parentId' => $parent,
            'data' => $data,
        ], fn ($value) => $value !== null);

        $nodes = [
            $node('trigger', WorkflowGraph::TRIGGER, 270, 0, ['label' => 'Pedido de cotação de cliente']),
            $node('read', WorkflowGraph::AGENT, 270, 120, ['label' => 'Ler o pedido e o caderno de encargos', 'capability' => 'documents.read_attachment',
                'instruction' => 'Lê o email e os anexos. Anota o que pedem, as quantidades, o local, o prazo de resposta e quem pede.']),
            $node('known', WorkflowGraph::CONDITION, 270, 240, ['label' => 'O cliente já existe no ERP?', 'capability' => 'erp.crm.search_accounts']),
            $node('update', WorkflowGraph::AGENT, 40, 370, ['label' => 'Actualizar a conta do cliente', 'capability' => 'erp.crm.update_account',
                'instruction' => 'Actualiza na conta do cliente o contacto de quem pediu, se for novo.']),
            $node('create', WorkflowGraph::AGENT, 500, 370, ['label' => 'Criar contacto e conta', 'capability' => 'erp.crm.create_contact',
                'instruction' => 'Cria o contacto da entidade que pediu a cotação.']),
            $node('lead', WorkflowGraph::AGENT, 270, 500, ['label' => 'Registar a oportunidade', 'capability' => 'erp.leads.create',
                'instruction' => 'Regista a oportunidade no ERP com o valor estimado e o prazo de resposta, se ainda não existir.']),
            [...$node('suppliers', WorkflowGraph::LOOP, 160, 630, ['label' => 'Para cada fornecedor a consultar', 'max' => 5,
                'items' => 'os fornecedores a consultar para os materiais e serviços pedidos, um por item (nome do fornecedor e o que lhe pedir)']), 'width' => 480, 'height' => 150],
            $node('ask_supplier', WorkflowGraph::HANDOFF, 120, 50, ['label' => 'Pedir cotação ao fornecedor', 'agent_id' => $procurementId, 'capability' => 'erp.procurement.create_rfq',
                'instruction' => 'Cria e envia o pedido de cotação a este fornecedor para os materiais e serviços indicados, com a data limite da resposta.'], 'suppliers'),
            $node('wait', WorkflowGraph::WAIT, 270, 830, ['label' => 'Esperar as cotações', 'hours' => 120]),
            $node('price', WorkflowGraph::AGENT, 270, 950, ['label' => 'Calcular o orçamento', 'capability' => 'erp.quotes.create',
                'instruction' => 'Com as cotações recebidas e o caderno de encargos, calcula o preço total com a margem da empresa.']),
            $node('big', WorkflowGraph::CONDITION, 270, 1070, ['label' => 'O total passa de 500 000 MZN?']),
            $node('approve', WorkflowGraph::APPROVAL, 40, 1200, ['label' => 'Aprovar o preço da proposta',
                'instruction' => 'Reveja o orçamento calculado e aprove o preço antes de a proposta seguir.']),
            $node('stop', WorkflowGraph::END, 40, 1330, ['label' => 'Fim sem proposta']),
            $node('draft', WorkflowGraph::AGENT, 500, 1200, ['label' => 'Preparar a proposta', 'capability' => 'documents.generate',
                'instruction' => 'Gera o documento da proposta com o preço, o prazo de execução e a validade.']),
            $node('send', WorkflowGraph::AGENT, 500, 1330, ['label' => 'Enviar a proposta ao cliente', 'capability' => 'comms.send_email',
                'instruction' => 'Envia a proposta a quem pediu, a responder ao email original.']),
            $node('end', WorkflowGraph::END, 500, 1460, ['label' => 'Fim']),
        ];

        $edge = fn (string $source, string $target, ?string $handle = null) => array_filter([
            'id' => "{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'sourceHandle' => $handle,
        ], fn ($value) => $value !== null);

        return [
            'nodes' => $nodes,
            'edges' => [
                $edge('trigger', 'read'),
                $edge('read', 'known'),
                $edge('known', 'update', 'yes'),
                $edge('known', 'create', 'no'),
                $edge('update', 'lead'),
                $edge('create', 'lead'),
                $edge('lead', 'suppliers'),
                $edge('suppliers', 'wait'),
                $edge('wait', 'price'),
                $edge('price', 'big'),
                $edge('big', 'approve', 'yes'),
                $edge('big', 'draft', 'no'),
                $edge('approve', 'draft', 'approved'),
                $edge('approve', 'stop', 'rejected'),
                $edge('draft', 'send'),
                $edge('send', 'end'),
            ],
        ];
    }
}
