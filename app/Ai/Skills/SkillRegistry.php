<?php

namespace App\Ai\Skills;

use App\Ai\Skills\Local\ClassifyEmail;
use App\Ai\Skills\Local\DraftEmailReply;
use App\Ai\Skills\Local\InboxSummary;
use App\Ai\Skills\Local\NotifyUser;
use App\Ai\Skills\Local\ReadAttachment;
use App\Ai\Skills\Local\ReadEmail;
use App\Ai\Skills\Local\ReadWebPage;
use App\Ai\Skills\Local\RecordTender;
use App\Ai\Skills\Local\RememberDecision;
use App\Ai\Skills\Local\ScheduleFollowUp;
use App\Ai\Skills\Local\SearchEmails;
use App\Ai\Skills\Local\SearchKnowledge;
use App\Ai\Skills\Local\SendEmail;

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
    ];

    /** Given to every agent, whatever its configuration (section 13.2). */
    public const ALWAYS_ON = ['memory.search'];

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
