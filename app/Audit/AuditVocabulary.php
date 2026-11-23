<?php

namespace App\Audit;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Models\AccessRole;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\BankTransaction;
use App\Models\Briefing;
use App\Models\Capability;
use App\Models\CompanyFact;
use App\Models\Connector;
use App\Models\Department;
use App\Models\DocumentFolder;
use App\Models\EmailMessage;
use App\Models\ErpConnection;
use App\Models\FollowUp;
use App\Models\GeneratedDocument;
use App\Models\Goal;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Mailbox;
use App\Models\Project;
use App\Models\Report;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Str;

/**
 * The audit trail in Portuguese (docs/DECISOES.md, "Auditoria pesquisável e
 * exportável"): what each recorded action means, the families the action
 * filter groups them in, and the kinds of object an entry may be about. An
 * action not named here reads as the capability it is (the key of a local
 * capability, or an approval asked for one), or as its key.
 */
final class AuditVocabulary
{
    /** @var array<string, string> */
    public const ACTIONS = [
        'access_role.created' => 'Papel criado',
        'access_role.updated' => 'Papel alterado',
        'access_role.deleted' => 'Papel apagado',
        'agenda.feed_created' => 'Ligação da agenda criada',
        'agenda.feed_revoked' => 'Ligação da agenda revogada',
        'agent.access_updated' => 'Acessos ao agente alterados',
        'agent.avatar_generated' => 'Foto do agente gerada',
        'agent.avatar_uploaded' => 'Foto do agente carregada',
        'agent.capabilities_granted_by_sync' => 'Capacidades dadas na sincronização do ERP',
        'agent.created' => 'Agente criado',
        'agent.updated' => 'Agente alterado',
        'agent.handoff' => 'Pedido passado a outro agente',
        'agent.installed_from_template' => 'Agente instalado a partir do modelo',
        'agent.refreshed_from_template' => 'Agente actualizado ao modelo',
        'agent.memory_updated' => 'Memória do agente corrigida',
        'agent.memory_deleted' => 'Memória do agente apagada',
        'agent.memory_forgotten' => 'Memória sobre uma pessoa apagada',
        'agent.proposed_created' => 'Agente proposto criado',
        'agent.reactivated' => 'Agente reactivado',
        'agent.suspended' => 'Agente suspenso',
        'agent.routine_created' => 'Rotina criada',
        'agent.routine_updated' => 'Rotina alterada',
        'agent.routine_deleted' => 'Rotina apagada',
        'agent.run' => 'Execução de um agente',
        'agent.trust_changed' => 'Nível de confiança alterado',
        'ai.cost_rollup' => 'Custos de IA somados',
        'ai.provider_key_set' => 'Chave de provedor de IA guardada',
        'ai.provider_key_removed' => 'Chave de provedor de IA removida',
        'approval.approved' => 'Acção aprovada',
        'approval.rejected' => 'Acção rejeitada',
        'approval.expired' => 'Aprovação expirada',
        'approval.escalated' => 'Aprovação passada a uma pessoa',
        'approval_rule.saved' => 'Regra de aprovação guardada',
        'approval_rule.removed' => 'Regra de aprovação removida',
        'audit.exported' => 'Auditoria exportada',
        'budget.extra_granted' => 'Gasto acima do orçamento autorizado',
        'budget.threshold' => 'Limiar do orçamento atingido',
        'calendar.connected' => 'Calendário ligado',
        'calendar.disconnected' => 'Calendário desligado',
        'capability.updated' => 'Capacidade alterada',
        'capability.risk_updated' => 'Risco de uma capacidade alterado',
        'code.run' => 'Código executado',
        'company_memory.created' => 'Facto da empresa criado',
        'company_memory.updated' => 'Facto da empresa alterado',
        'company_memory.deleted' => 'Facto da empresa apagado',
        'company_memory.approved' => 'Facto da empresa aprovado',
        'company_memory.rejected' => 'Facto da empresa rejeitado',
        'connector.created' => 'Conector criado',
        'connector.updated' => 'Conector alterado',
        'connector.deleted' => 'Conector apagado',
        'connector.global_activated' => 'Conector da plataforma activado',
        'connector.global_deactivated' => 'Conector da plataforma desactivado',
        'connector.tool_call' => 'Chamada a um conector',
        'connector.tools_list' => 'Listagem das ferramentas de um conector',
        'documents.generated' => 'Documento gerado',
        'documents.filed' => 'Documento arquivado',
        'documents.deleted' => 'Documento apagado',
        'documents.folder_created' => 'Pasta de documentos criada',
        'documents.folder_renamed' => 'Pasta de documentos renomeada',
        'documents.folder_deleted' => 'Pasta de documentos apagada',
        'email.acknowledged' => 'Recepção de um email confirmada',
        'email.draft_discarded' => 'Rascunho de email descartado',
        'email.draft_kept' => 'Rascunho de email guardado',
        'email.reclassified' => 'Email reclassificado',
        'email.sent_by_user' => 'Email enviado',
        'email.shared_with_agent' => 'Email partilhado com um agente',
        'email_route.updated' => 'Regra de email alterada',
        'erp.tool_call' => 'Chamada ao ERP',
        'erp.tools_list' => 'Listagem das ferramentas do ERP',
        'erp.connection_test' => 'Teste da ligação ao ERP',
        'erp.connection_updated' => 'Ligação ao ERP alterada',
        'erp.file_fetch' => 'Ficheiro do ERP descarregado',
        'follow_up.updated' => 'Lembrete alterado',
        'goal.created' => 'Objectivo criado',
        'goal.updated' => 'Objectivo alterado',
        'initiative.started' => 'Iniciativa começada',
        'knowledge.created' => 'Artigo de conhecimento criado',
        'knowledge.updated' => 'Artigo de conhecimento alterado',
        'knowledge.deleted' => 'Artigo de conhecimento apagado',
        'knowledge.approved' => 'Artigo de conhecimento aprovado',
        'knowledge.rejected' => 'Artigo de conhecimento rejeitado',
        'knowledge.uploaded' => 'Ficheiro carregado no conhecimento',
        'knowledge.imported' => 'Conhecimento importado',
        'knowledge.versioned' => 'Nova versão de um ficheiro',
        'knowledge.domain_created' => 'Domínio de conhecimento criado',
        'knowledge.domain_updated' => 'Domínio de conhecimento alterado',
        'knowledge.domain_deleted' => 'Domínio de conhecimento apagado',
        'knowledge.folder_created' => 'Pasta de conhecimento criada',
        'knowledge.folder_deleted' => 'Pasta de conhecimento apagada',
        'mailbox.created' => 'Caixa de email criada',
        'mailbox.updated' => 'Caixa de email alterada',
        'mailbox.deleted' => 'Caixa de email apagada',
        'mailbox.moved_in' => 'Caixa de email recebida de outra empresa',
        'mailbox.moved_out' => 'Caixa de email passada a outra empresa',
        'memory.told' => 'Pessoa disse algo aos agentes',
        'memory.preference_updated' => 'Preferência de memória alterada',
        'org.changed' => 'Organigrama alterado',
        'platform.health_checked' => 'Configuração verificada',
        'project.created' => 'Projecto criado',
        'project.updated' => 'Projecto alterado',
        'project.channel_opened' => 'Canal do projecto aberto',
        'question.answered' => 'Pergunta de um agente respondida',
        'report.reviewed' => 'Relatório revisto',
        'report.deleted' => 'Relatório apagado',
        'routine.created' => 'Rotina criada',
        'routine.updated' => 'Rotina alterada',
        'routine.deleted' => 'Rotina apagada',
        'routine.run_now' => 'Rotina corrida agora',
        'settings.attachments_updated' => 'Definições de anexos alteradas',
        'settings.brand_updated' => 'Marca alterada',
        'settings.brand_asset_saved' => 'Ficheiro da marca guardado',
        'settings.brand_asset_removed' => 'Ficheiro da marca removido',
        'skill.created' => 'Skill criada',
        'skill.updated' => 'Skill alterada',
        'skill.deleted' => 'Skill apagada',
        'skill.imported' => 'Skill importada',
        'skill.agents_updated' => 'Agentes de uma skill alterados',
        'skill.global_activated' => 'Skill da plataforma activada',
        'skill.global_deactivated' => 'Skill da plataforma desactivada',
        'task.created' => 'Tarefa criada',
        'task.status' => 'Estado de uma tarefa mudado',
        'task.attachments' => 'Anexos de uma tarefa',
        'task.collaborator_invited' => 'Colaborador convidado para uma tarefa',
        'task.collaborator_removed' => 'Colaborador retirado de uma tarefa',
        'tenant.created' => 'Empresa criada',
        'tenant.updated' => 'Empresa alterada pela plataforma',
        'tenant.exported' => 'Dados da empresa exportados',
        'user.password_changed' => 'Palavra-passe mudada',
        'user.avatar_uploaded' => 'Foto de perfil carregada',
        'web.search' => 'Pesquisa na internet',
        'workflow.created' => 'Fluxo de trabalho criado',
        'workflow.updated' => 'Fluxo de trabalho alterado',
        'workflow.deleted' => 'Fluxo de trabalho apagado',
        'workflow.status' => 'Estado de um fluxo de trabalho mudado',
        'workflow.proposed' => 'Fluxo de trabalho proposto',
        'workflow.decide' => 'Decisão num fluxo de trabalho',
    ];

    /** The first part of an action ("erp" in "erp.tool_call"), as the action filter groups them. */
    private const FAMILIES = [
        'access_role' => 'Papéis e acessos',
        'agenda' => 'Agenda',
        'agent' => 'Agentes',
        'agents' => 'Agentes (capacidades)',
        'ai' => 'IA',
        'approval' => 'Aprovações',
        'approval_rule' => 'Regras de aprovação',
        'audit' => 'Auditoria',
        'bank' => 'Banco',
        'budget' => 'Orçamento',
        'calendar' => 'Calendário',
        'capability' => 'Capacidades',
        'code' => 'Código',
        'comms' => 'Comunicação',
        'company_memory' => 'Memória da empresa',
        'connector' => 'Conectores',
        'documents' => 'Documentos',
        'email' => 'Email',
        'email_route' => 'Regras de email',
        'erp' => 'ERP',
        'follow_up' => 'Lembretes',
        'followups' => 'Lembretes (capacidades)',
        'goal' => 'Objectivos',
        'initiative' => 'Iniciativas',
        'knowledge' => 'Conhecimento',
        'mailbox' => 'Caixas de email',
        'memory' => 'Memória',
        'notify' => 'Notificações',
        'org' => 'Organigrama',
        'platform' => 'Plataforma',
        'project' => 'Projectos',
        'question' => 'Perguntas',
        'questions' => 'Perguntas (capacidades)',
        'report' => 'Relatórios',
        'reports' => 'Relatórios (capacidades)',
        'routine' => 'Rotinas',
        'settings' => 'Definições',
        'skill' => 'Skills',
        'task' => 'Tarefas',
        'tasks' => 'Tarefas (capacidades)',
        'tenant' => 'Empresa',
        'user' => 'Pessoas',
        'web' => 'Internet',
        'workflow' => 'Fluxos de trabalho',
    ];

    /**
     * What an entry may be about: the filter's short key, the model, the
     * name in Portuguese, and where it opens (null: nowhere to open).
     *
     * @var array<string, array{0: class-string, 1: string, 2: string|null}>
     */
    private const SUBJECTS = [
        'agent' => [Agent::class, 'Agente', '/agents/{id}'],
        'task' => [Task::class, 'Tarefa', '/tasks/{id}'],
        'approval' => [Approval::class, 'Aprovação', '/approvals?vista=todas&aprovacao={id}'],
        'run' => [AgentRun::class, 'Execução', '/settings/runs/{id}'],
        'routine' => [AgentRoutine::class, 'Rotina', '/settings/routines'],
        'agent_memory' => [AgentMemory::class, 'Memória de um agente', null],
        'report' => [Report::class, 'Relatório', '/reports/{id}'],
        'briefing' => [Briefing::class, 'Briefing', '/briefings/{id}'],
        'document' => [GeneratedDocument::class, 'Documento', '/documents/{id}'],
        'document_folder' => [DocumentFolder::class, 'Pasta de documentos', null],
        'knowledge' => [KnowledgeItem::class, 'Artigo de conhecimento', '/knowledge/{id}'],
        'knowledge_domain' => [KnowledgeDomain::class, 'Domínio de conhecimento', '/knowledge/domains'],
        'knowledge_folder' => [KnowledgeFolder::class, 'Pasta de conhecimento', null],
        'company_fact' => [CompanyFact::class, 'Facto da empresa', '/settings/company-memory'],
        'email' => [EmailMessage::class, 'Email', '/inbox/{id}'],
        'mailbox' => [Mailbox::class, 'Caixa de email', '/settings/mailboxes'],
        'follow_up' => [FollowUp::class, 'Lembrete', null],
        'workflow' => [Workflow::class, 'Fluxo de trabalho', '/settings/workflows/{id}/edit'],
        'skill' => [Skill::class, 'Skill', '/settings/skills/{id}/edit'],
        'capability' => [Capability::class, 'Capacidade', '/settings/capabilities'],
        'connector' => [Connector::class, 'Conector', '/settings/integrations'],
        'erp' => [ErpConnection::class, 'Ligação ao ERP', '/settings/integrations/erp'],
        'user' => [User::class, 'Pessoa', '/settings/users/{id}/edit'],
        'access_role' => [AccessRole::class, 'Papel', '/settings/roles'],
        'department' => [Department::class, 'Departamento', '/settings/departments'],
        'project' => [Project::class, 'Projecto', '/projects'],
        'goal' => [Goal::class, 'Objectivo', '/goals'],
        'bank_transaction' => [BankTransaction::class, 'Movimento bancário', null],
        'tenant' => [Tenant::class, 'Empresa', null],
    ];

    /** The attributes that name an object, in the order tried. */
    private const NAME_ATTRIBUTES = ['name', 'title', 'subject', 'address', 'email', 'action_summary', 'filename', 'key', 'description', 'content'];

    /**
     * @param  array<string, string>  $capabilities  capability names by key
     */
    public static function action(string $action, array $capabilities = []): string
    {
        return self::ACTIONS[$action]
            ?? $capabilities[$action]
            ?? (str_starts_with($action, 'bank.transaction_') ? 'Movimento bancário: '.str_replace('_', ' ', substr($action, 17)) : $action);
    }

    public static function family(string $action): string
    {
        return Str::before($action, '.');
    }

    public static function familyLabel(string $family): string
    {
        return self::FAMILIES[$family] ?? Str::ucfirst(str_replace('_', ' ', $family));
    }

    public static function result(AuditResult $result): string
    {
        return match ($result) {
            AuditResult::Ok => 'Ok',
            AuditResult::Denied => 'Recusada',
            AuditResult::Error => 'Erro',
        };
    }

    public static function actorType(ActorType $type): string
    {
        return match ($type) {
            ActorType::User => 'Pessoa',
            ActorType::Agent => 'Agente',
            ActorType::System => 'Sistema',
            ActorType::PlatformAdmin => 'Plataforma',
        };
    }

    /**
     * The kinds of object, for the filter.
     *
     * @return array<string, class-string>
     */
    public static function subjectClasses(): array
    {
        return array_map(fn (array $subject) => $subject[0], self::SUBJECTS);
    }

    /** The filter's key for a kind of object, null for a kind it does not know. */
    public static function subjectKey(?string $class): ?string
    {
        $key = array_search($class, self::subjectClasses(), true);

        return is_string($key) ? $key : null;
    }

    public static function subjectLabel(string $class): string
    {
        $key = self::subjectKey($class);

        return $key !== null ? self::SUBJECTS[$key][1] : class_basename($class);
    }

    public static function subjectUrl(string $class, int|string $id): ?string
    {
        $key = self::subjectKey($class);
        $pattern = $key !== null ? self::SUBJECTS[$key][2] : null;

        return $pattern === null ? null : str_replace('{id}', (string) $id, $pattern);
    }

    /** What names a loaded object, in a line. */
    public static function subjectName(object $subject): ?string
    {
        $attributes = method_exists($subject, 'getAttributes') ? $subject->getAttributes() : [];

        foreach (self::NAME_ATTRIBUTES as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(Str::squish($value), 80);
            }
        }

        return null;
    }
}
