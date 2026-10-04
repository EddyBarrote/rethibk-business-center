<?php

namespace App\Ai\Skills;

use App\Ai\Skills\Local\AskHuman;
use App\Ai\Skills\Local\BrowseKnowledge;
use App\Ai\Skills\Local\BudgetOverride;
use App\Ai\Skills\Local\ClassifyEmail;
use App\Ai\Skills\Local\ClientSheet;
use App\Ai\Skills\Local\CompareQuotes;
use App\Ai\Skills\Local\CreateTask;
use App\Ai\Skills\Local\DetectIssues;
use App\Ai\Skills\Local\DraftEmailReply;
use App\Ai\Skills\Local\DraftReport;
use App\Ai\Skills\Local\GenerateDocument;
use App\Ai\Skills\Local\ImportBankStatement;
use App\Ai\Skills\Local\InboxSummary;
use App\Ai\Skills\Local\ListContracts;
use App\Ai\Skills\Local\ListPurchaseRequests;
use App\Ai\Skills\Local\ListTasks;
use App\Ai\Skills\Local\ListUnreconciled;
use App\Ai\Skills\Local\MatchCandidate;
use App\Ai\Skills\Local\MonthSummary;
use App\Ai\Skills\Local\NotifyUser;
use App\Ai\Skills\Local\OrganisationOverview;
use App\Ai\Skills\Local\ProjectMargins;
use App\Ai\Skills\Local\PublishBriefing;
use App\Ai\Skills\Local\RateSupplier;
use App\Ai\Skills\Local\ReadKnowledge;
use App\Ai\Skills\Local\ReadAttachment;
use App\Ai\Skills\Local\ReadEmail;
use App\Ai\Skills\Local\ReadWebPage;
use App\Ai\Skills\Local\RecordTender;
use App\Ai\Skills\Local\RememberDecision;
use App\Ai\Skills\Local\SaveKnowledge;
use App\Ai\Skills\Local\ScheduleFollowUp;
use App\Ai\Skills\Local\SearchEmails;
use App\Ai\Skills\Local\SearchKnowledge;
use App\Ai\Skills\Local\SendEmail;
use App\Ai\Skills\Local\SlaStatus;
use App\Ai\Skills\Local\SuggestBankMatch;
use App\Ai\Skills\Local\SupplierScores;
use App\Ai\Skills\Local\UpdatePurchaseRequest;
use App\Ai\Skills\Local\UpdateTaskStatus;

/**
 * The local skills the platform ships (section 7.3). New skills are added
 * here and appear in every tenant's catalogue at the next sync.
 */
final class SkillRegistry
{
    /** @var list<class-string<LocalSkill>> */
    private const SKILLS = [
        SearchKnowledge::class,
        RememberDecision::class,
        // Knowledge base and generated documents (docs/CONHECIMENTO.md)
        BrowseKnowledge::class,
        ReadKnowledge::class,
        SaveKnowledge::class,
        GenerateDocument::class,
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
        // Requested by the platform when a cap runs out; never given to agents.
        BudgetOverride::class,
    ];

    /** Given to every agent, whatever its configuration (section 13.2). */
    public const ALWAYS_ON = ['memory.search', 'knowledge.browse', 'knowledge.read', 'tasks.create', 'tasks.ask_human', 'tasks.update_status', 'tasks.list'];

    /**
     * @return list<LocalSkill>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::SKILLS);
    }

    public function find(string $key): ?LocalSkill
    {
        foreach ($this->all() as $skill) {
            if ($skill->key() === $key) {
                return $skill;
            }
        }

        return null;
    }
}
