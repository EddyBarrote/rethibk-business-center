<?php

namespace App\Email;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use Illuminate\Support\Str;

/**
 * Section 9.2, step 5: In-Reply-To / References first, then normalised
 * subject plus a shared participant within the last 30 days.
 */
final class ThreadResolver
{
    /**
     * @param  list<string>  $references
     * @param  list<string>  $participants
     */
    public function resolve(Mailbox $mailbox, ?string $inReplyTo, array $references, string $subject, array $participants): EmailThread
    {
        $ids = array_values(array_filter([$inReplyTo, ...array_reverse($references)]));

        if ($ids !== []) {
            $parent = EmailMessage::query()
                ->where('mailbox_id', $mailbox->id)
                ->whereIn('message_id_header', $ids)
                ->whereNotNull('thread_id')
                ->latest('id')
                ->first();

            if ($parent?->thread_id !== null && ($thread = EmailThread::query()->find($parent->thread_id)) !== null) {
                return $thread;
            }
        }

        $normalized = self::normalizeSubject($subject);

        if ($normalized !== '') {
            $candidates = EmailThread::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('subject_normalized', $normalized)
                ->where('last_message_at', '>=', now()->subDays(30))
                ->latest('last_message_at')
                ->limit(5)
                ->get();

            foreach ($candidates as $thread) {
                $known = $thread->messages()->get(['from_address', 'to', 'cc'])
                    ->flatMap(fn (EmailMessage $m) => [$m->from_address, ...($m->to ?? []), ...($m->cc ?? [])])
                    ->filter()
                    ->map(fn (string $a) => Str::lower($a));

                if ($known->intersect($participants)->isNotEmpty()) {
                    return $thread;
                }
            }
        }

        return EmailThread::query()->create([
            'mailbox_id' => $mailbox->id,
            'subject_normalized' => $normalized,
            'message_count' => 0,
        ]);
    }

    public static function normalizeSubject(string $subject): string
    {
        $subject = Str::lower(trim($subject));

        do {
            $before = $subject;
            $subject = trim((string) preg_replace('/^(re|res|fw|fwd|enc|tr|aw|wg)\s*(\[\d+\])?\s*:\s*/iu', '', $subject));
        } while ($subject !== $before);

        return Str::limit((string) preg_replace('/\s+/u', ' ', $subject), 250, '');
    }
}
