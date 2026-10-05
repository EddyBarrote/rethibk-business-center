<?php

namespace App\Enums;

/**
 * What a person can do in the system, as the access matrix grants it
 * (docs/DECISOES.md, realinhamento L9 and "Caixas de email por pessoa").
 * Every permission is checked on the server, not only by hiding buttons.
 */
enum Permission: string
{
    // Trabalho
    case ManageWork = 'work.manage';
    case ReadAllWork = 'work.read_all';
    case ManageProjects = 'projects.manage';
    case ManageOrg = 'org.manage';

    // Agentes
    case ManageAgents = 'agents.manage';
    case GrantAgentAccess = 'agents.grant_access';
    case TalkToAllAgents = 'agents.talk_all';
    case ManageAgentMemory = 'agents.memory';
    case ConfirmTrustLevels = 'trust.confirm';
    case ManageCatalog = 'catalog.manage';

    // Conversas
    case ReadAllConversations = 'conversations.read_all';
    case RequestConversationReports = 'conversations.report';

    // Emails
    case ConnectOwnMailboxes = 'emails.own_mailboxes';
    case ReadTriageEmails = 'emails.triage';
    case ManageMailboxes = 'emails.manage_mailboxes';

    // Conhecimento
    case WriteKnowledge = 'knowledge.write';
    case ManageKnowledge = 'knowledge.manage';

    // Documentos
    case CreateDocuments = 'documents.create';
    case ReadAllDocuments = 'documents.read_all';
    case ReadAllReports = 'reports.read_all';

    // Aprovações
    case DecideAllApprovals = 'approvals.decide_all';

    // Custos de IA
    case ViewCosts = 'costs.view';
    case ManageCosts = 'costs.manage';

    // Empresa
    case ManagePeople = 'people.manage';
    case ManageCompany = 'company.manage';

    public function group(): string
    {
        return match ($this) {
            self::ManageWork, self::ReadAllWork, self::ManageProjects, self::ManageOrg => 'Trabalho',
            self::ManageAgents, self::GrantAgentAccess, self::TalkToAllAgents, self::ManageAgentMemory, self::ConfirmTrustLevels, self::ManageCatalog => 'Agentes',
            self::ReadAllConversations, self::RequestConversationReports => 'Conversas',
            self::ConnectOwnMailboxes, self::ReadTriageEmails, self::ManageMailboxes => 'Emails',
            self::WriteKnowledge, self::ManageKnowledge => 'Conhecimento',
            self::CreateDocuments, self::ReadAllDocuments, self::ReadAllReports => 'Documentos',
            self::DecideAllApprovals => 'Aprovações',
            self::ViewCosts, self::ManageCosts => 'Custos de IA',
            self::ManagePeople, self::ManageCompany => 'Empresa',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ManageWork => 'Chefiar',
            self::ReadAllWork => 'Ver todo o trabalho',
            self::ManageProjects => 'Gerir objectivos e projectos',
            self::ManageOrg => 'Mudar o organigrama',
            self::ManageAgents => 'Criar e configurar agentes',
            self::GrantAgentAccess => 'Dar acesso a agentes',
            self::TalkToAllAgents => 'Falar com todos os agentes',
            self::ManageAgentMemory => 'Corrigir a memória de qualquer agente',
            self::ConfirmTrustLevels => 'Confirmar níveis de confiança',
            self::ManageCatalog => 'Gerir capacidades, skills e conectores',
            self::ReadAllConversations => 'Ler as conversas de todos',
            self::RequestConversationReports => 'Pedir relatórios sobre conversas',
            self::ConnectOwnMailboxes => 'Ligar as suas caixas de email',
            self::ReadTriageEmails => 'Ver emails de triagem',
            self::ManageMailboxes => 'Gerir todas as caixas de email',
            self::WriteKnowledge => 'Escrever conhecimento',
            self::ManageKnowledge => 'Gerir todo o conhecimento',
            self::CreateDocuments => 'Gerar documentos',
            self::ReadAllDocuments => 'Ver todos os ficheiros',
            self::ReadAllReports => 'Ver todos os documentos e briefings',
            self::DecideAllApprovals => 'Aprovar acções de qualquer agente',
            self::ViewCosts => 'Ver custos de IA',
            self::ManageCosts => 'Autorizar gastos acima do orçamento',
            self::ManagePeople => 'Gerir pessoas e papéis',
            self::ManageCompany => 'Administrar a empresa',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManageWork => 'Criar tarefas, dar trabalho a pessoas e ver o trabalho da sua área.',
            self::ReadAllWork => 'Abrir todas as tarefas da empresa. As conversas com os agentes continuam privadas.',
            self::ManageProjects => 'Criar e mudar objectivos e projectos.',
            self::ManageOrg => 'Dizer a quem responde cada pessoa e cada agente.',
            self::ManageAgents => 'Criar, editar, suspender e reactivar agentes, com as suas instruções, capacidades e orçamento.',
            self::GrantAgentAccess => 'Dizer quem fala com os agentes do seu departamento.',
            self::TalkToAllAgents => 'Conversar e pedir trabalho a qualquer agente sem acesso individual.',
            self::ManageAgentMemory => 'Ver, corrigir e apagar o que qualquer agente memorizou. Cada pessoa já o faz nos agentes que lhe respondem.',
            self::ConfirmTrustLevels => 'Confirmar as mudanças de nível de confiança que o Chief of Staff propõe.',
            self::ManageCatalog => 'Ligar e desligar capacidades, escrever skills e ligar conectores.',
            self::ReadAllConversations => 'Abrir as conversas de qualquer pessoa com os agentes.',
            self::RequestConversationReports => 'Pedir ao Chief of Staff que pesquise as conversas e faça um report.',
            self::ConnectOwnMailboxes => 'Ligar as suas próprias caixas de email e escolher que agentes as lêem.',
            self::ReadTriageEmails => 'Ver os emails que chegam às caixas dos agentes, como a da Triagem.',
            self::ManageMailboxes => 'Criar caixas de email para qualquer pessoa ou agente e dizer quem as gere.',
            self::WriteKnowledge => 'Escrever artigos e carregar ficheiros nos domínios que pode abrir.',
            self::ManageKnowledge => 'Abrir, rever e organizar todos os domínios, incluindo os restritos.',
            self::CreateDocuments => 'Gerar e carregar documentos.',
            self::ReadAllDocuments => 'Abrir os ficheiros de qualquer pessoa ou agente.',
            self::ReadAllReports => 'Abrir os documentos e briefings de todos, não só os seus.',
            self::DecideAllApprovals => 'Aprovar ou rejeitar o que qualquer agente pede, não só os da sua responsabilidade.',
            self::ViewCosts => 'Ver quanto os agentes gastam em IA no painel.',
            self::ManageCosts => 'Aprovar o gasto de um agente que passou o seu orçamento.',
            self::ManagePeople => 'Criar e editar pessoas, os seus papéis e excepções, e os próprios papéis.',
            self::ManageCompany => 'Departamentos, marca e ligação ao ERP.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string, group: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label(), 'description' => $p->description(), 'group' => $p->group()], self::cases());
    }

    /**
     * The CEO role always keeps these, so nobody locks everyone out.
     *
     * @return list<self>
     */
    public static function ceoKeeps(): array
    {
        return [self::ManageCompany, self::ManagePeople];
    }
}
