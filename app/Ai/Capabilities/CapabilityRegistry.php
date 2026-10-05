<?php

namespace App\Ai\Capabilities;

use App\Ai\Capabilities\Local\AskHuman;
use App\Ai\Capabilities\Local\BudgetOverride;
use App\Ai\Capabilities\Local\ClassifyEmail;
use App\Ai\Capabilities\Local\ClientSheet;
use App\Ai\Capabilities\Local\CompareQuotes;
use App\Ai\Capabilities\Local\ConfirmBankMatch;
use App\Ai\Capabilities\Local\CreateTask;
use App\Ai\Capabilities\Local\DetectIssues;
use App\Ai\Capabilities\Local\DraftEmailReply;
use App\Ai\Capabilities\Local\DraftReport;
use App\Ai\Capabilities\Local\EscalateUrgent;
use App\Ai\Capabilities\Local\ImportBankStatement;
use App\Ai\Capabilities\Local\InboxSummary;
use App\Ai\Capabilities\Local\ListContracts;
use App\Ai\Capabilities\Local\ListPurchaseRequests;
use App\Ai\Capabilities\Local\ListTasks;
use App\Ai\Capabilities\Local\ListUnreconciled;
use App\Ai\Capabilities\Local\LoadSkill;
use App\Ai\Capabilities\Local\MatchCandidate;
use App\Ai\Capabilities\Local\MonthSummary;
use App\Ai\Capabilities\Local\NotifyUser;
use App\Ai\Capabilities\Local\OrganisationOverview;
use App\Ai\Capabilities\Local\ProjectMargins;
use App\Ai\Capabilities\Local\ProposeTrustLevel;
use App\Ai\Capabilities\Local\PublishBriefing;
use App\Ai\Capabilities\Local\RateSupplier;
use App\Ai\Capabilities\Local\ReadAttachment;
use App\Ai\Capabilities\Local\ReadEmail;
use App\Ai\Capabilities\Local\ReadSkillFile;
use App\Ai\Capabilities\Local\ReadWebPage;
use App\Ai\Capabilities\Local\RecordTender;
use App\Ai\Capabilities\Local\RememberDecision;
use App\Ai\Capabilities\Local\ReviewApproval;
use App\Ai\Capabilities\Local\ScheduleFollowUp;
use App\Ai\Capabilities\Local\SearchConversations;
use App\Ai\Capabilities\Local\SearchEmails;
use App\Ai\Capabilities\Local\SearchKnowledge;
use App\Ai\Capabilities\Local\SendEmail;
use App\Ai\Capabilities\Local\SlaStatus;
use App\Ai\Capabilities\Local\SuggestBankMatch;
use App\Ai\Capabilities\Local\SupplierScores;
use App\Ai\Capabilities\Local\UpdatePurchaseRequest;
use App\Ai\Capabilities\Local\UpdateTaskStatus;

/**
 * The local capabilities the platform ships (section 7.3), i.e. tools written
 * in PHP. Add a new one to the list below, or, from a module of its own, call
 * CapabilityRegistry::register() in a service provider (docs/CAPACIDADES.md).
 * Either way it appears in every tenant's catalogue at the next sync.
 */
final class CapabilityRegistry
{
    /** @var list<class-string<LocalCapability>> */
    private const CAPABILITIES = [
        SearchKnowledge::class,
        RememberDecision::class,
        SendEmail::class,
        // E03: triage
        ReadEmail::class,
        SearchEmails::class,
        ReadAttachment::class,
        ClassifyEmail::class,
        DraftEmailReply::class,
        NotifyUser::class,
        ScheduleFollowUp::class,
        RecordTender::class,
        ReadWebPage::class,
        InboxSummary::class,
        // E04: Chief of Staff
        OrganisationOverview::class,
        DetectIssues::class,
        PublishBriefing::class,
        DraftReport::class,
        // E05: finance
        ImportBankStatement::class,
        ListUnreconciled::class,
        SuggestBankMatch::class,
        ConfirmBankMatch::class,
        ProjectMargins::class,
        MonthSummary::class,
        // E06: procurement
        ListPurchaseRequests::class,
        UpdatePurchaseRequest::class,
        CompareQuotes::class,
        RateSupplier::class,
        SupplierScores::class,
        ListContracts::class,
        // E07: human resources
        MatchCandidate::class,
        // E08: client manager
        ClientSheet::class,
        SlaStatus::class,
        // Tasks and conversations (Paperclip-style collaboration)
        CreateTask::class,
        AskHuman::class,
        UpdateTaskStatus::class,
        ListTasks::class,
        // Chief of Staff (realinhamento L10, L11) and urgent escalation.
        ReviewApproval::class,
        SearchConversations::class,
        ProposeTrustLevel::class,
        EscalateUrgent::class,
        // Skills (docs/CAPACIDADES.md): given to agents that have skills.
        LoadSkill::class,
        ReadSkillFile::class,
        // Requested by the platform when a cap runs out; never given to agents.
        BudgetOverride::class,
    ];

    /** @var list<class-string<LocalCapability>> */
    private static array $registered = [];

    /** @var list<string> */
    private static array $alwaysOn = [];

    /** Used by the platform itself, never given to agents nor listed for admins. */
    public const INTERNAL = ['budget.override'];

    /** Given to every agent that has at least one usable skill. */
    public const SKILL_TOOLS = ['skills.load', 'skills.read_file'];

    /** Given to every agent, whatever its configuration (section 13.2). */
    public const ALWAYS_ON = ['memory.search', 'tasks.create', 'tasks.ask_human', 'tasks.update_status', 'tasks.list', 'escalate.urgent'];

    /** What the Chief of Staff gets for its role (realinhamento L10, L11). */
    public const CHIEF_OF_STAFF = ['approvals.review', 'conversations.search', 'agents.set_trust_level'];

    /**
     * @return list<LocalCapability>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), array_values(array_unique([...self::CAPABILITIES, ...self::$registered])));
    }

    /**
     * Adds local capabilities from outside this file, e.g. a module's service
     * provider: CapabilityRegistry::register(GenerateDocument::class).
     *
     * @param  class-string<LocalCapability>  ...$classes
     */
    public static function register(string ...$classes): void
    {
        self::$registered = array_values(array_unique([...self::$registered, ...$classes]));
    }

    /**
     * Keys admins never assign by hand: internal ones and the skill tools,
     * which come with the skills.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        return [...self::INTERNAL, ...self::SKILL_TOOLS];
    }

    /**
     * Keys every agent gets, including those registered with alwaysOn().
     *
     * @return list<string>
     */
    public static function alwaysOn(): array
    {
        return array_values(array_unique([...self::ALWAYS_ON, ...self::$alwaysOn]));
    }

    /**
     * Gives these capability keys to every agent, whatever its configuration.
     */
    public static function giveToEveryAgent(string ...$keys): void
    {
        self::$alwaysOn = array_values(array_unique([...self::$alwaysOn, ...$keys]));
    }

    public function find(string $key): ?LocalCapability
    {
        foreach ($this->all() as $capability) {
            if ($capability->key() === $key) {
                return $capability;
            }
        }

        return null;
    }
}
