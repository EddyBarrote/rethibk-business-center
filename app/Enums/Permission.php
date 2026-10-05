<?php

namespace App\Enums;

/**
 * What a role can do in the access matrix (docs/DECISOES.md, realinhamento L9).
 */
enum Permission: string
{
    case ManageCompany = 'company.manage';
    case ManageWork = 'work.manage';
    case GrantAgentAccess = 'agents.grant_access';
    case TalkToAllAgents = 'agents.talk_all';
    case DecideAllApprovals = 'approvals.decide_all';
    case ReadAllConversations = 'conversations.read_all';
    case RequestConversationReports = 'conversations.report';
    case ConfirmTrustLevels = 'trust.confirm';

    public function label(): string
    {
        return match ($this) {
            self::ManageCompany => 'Administrar a empresa',
            self::ManageWork => 'Chefiar',
            self::GrantAgentAccess => 'Dar acesso a agentes',
            self::TalkToAllAgents => 'Falar com todos os agentes',
            self::DecideAllApprovals => 'Aprovar acções de qualquer agente',
            self::ReadAllConversations => 'Ler as conversas de todos',
            self::RequestConversationReports => 'Pedir relatórios sobre conversas',
            self::ConfirmTrustLevels => 'Confirmar níveis de confiança',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManageCompany => 'Pessoas, papéis, agentes, capacidades, skills, marca e ligação ao ERP.',
            self::ManageWork => 'Criar tarefas e projectos, dar trabalho a pessoas, ver o trabalho da área.',
            self::GrantAgentAccess => 'Dizer quem fala com os agentes; sem administrar a empresa, só os agentes do seu departamento.',
            self::TalkToAllAgents => 'Conversar e pedir trabalho a qualquer agente sem acesso individual.',
            self::DecideAllApprovals => 'Aprovar ou rejeitar o que qualquer agente pede, não só os da sua responsabilidade.',
            self::ReadAllConversations => 'Abrir as conversas de qualquer pessoa com os agentes.',
            self::RequestConversationReports => 'Pedir ao Chief of Staff que pesquise as conversas e faça um report.',
            self::ConfirmTrustLevels => 'Confirmar as mudanças de nível de confiança que o Chief of Staff propõe.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label(), 'description' => $p->description()], self::cases());
    }
}
