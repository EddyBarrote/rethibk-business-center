<?php

namespace App\Email;

use App\Enums\EmailStatus;
use App\Models\AgentRun;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One open draft per email (docs/DECISOES.md, "Um só rascunho por email"):
 * an agent that drafts again for the same email rewrites its open draft
 * instead of adding another, and never rewrites one a person wrote or changed.
 * The same holds for a new email to the same people in the same conversation.
 * The agent's draft is a proposal until a person keeps it (docs/DECISOES.md,
 * "Rascunho só quando a pessoa escolhe"): only proposals are rewritten.
 */
final class AgentDrafts
{
    /**
     * The open draft that answers this email in the mailbox, if there is one.
     */
    public function replyTo(Mailbox $mailbox, EmailMessage $original): ?EmailMessage
    {
        if (blank($original->message_id_header)) {
            return null;
        }

        return $this->open($mailbox)->where('in_reply_to', $original->message_id_header)->latest('id')->first();
    }

    /**
     * The open draft of a new email (no reply) to the same people that an
     * agent left in this run's conversation.
     *
     * @param  list<string>  $to
     */
    public function newEmail(Mailbox $mailbox, AgentRun $run, array $to, ?string $recipient): ?EmailMessage
    {
        $runs = $run->task_id !== null
            ? AgentRun::query()->where('task_id', $run->task_id)->select('id')
            : AgentRun::query()->whereKey($run->id)->select('id');

        $key = $this->recipients($to, $recipient);

        return $this->open($mailbox)->whereNull('in_reply_to')->whereIn('agent_run_id', $runs)->latest('id')->get()
            ->first(fn (EmailMessage $d) => $this->recipients($d->to ?? [], $d->pending_recipient) === $key);
    }

    /**
     * Whether a person wrote, kept or changed the draft, so no agent rewrites it.
     */
    public function isPersons(EmailMessage $draft): bool
    {
        return $draft->written_by_user_id !== null || $draft->kept_at !== null || $draft->edited_by_person_at !== null;
    }

    /**
     * Rewrites the agent's open proposal with this run's text. A proposal is
     * never on the server, so there is no copy to replace.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function rewrite(EmailMessage $draft, AgentRun $run, array $attributes): EmailMessage
    {
        $draft->fill([...$attributes, 'agent_run_id' => $run->id, 'read_at' => now()])->save();

        return $draft;
    }

    /**
     * The mailbox the email a task came from arrived in, when the person owns
     * it: the answer goes out from where the client wrote to.
     */
    public function sourceMailbox(AgentRun $run): ?Mailbox
    {
        $source = $this->sourceEmail($run);
        $person = $run->task->user ?? $run->requestedBy;

        return $source !== null && $person !== null && $source->mailbox?->isOwnedBy($person) ? $source->mailbox : null;
    }

    /**
     * The email the run's task came from, if it came from one.
     */
    public function sourceEmail(AgentRun $run): ?EmailMessage
    {
        $task = $run->task;

        return $task !== null && $task->source_type === (new EmailMessage)->getMorphClass()
            ? EmailMessage::query()->with('mailbox')->find($task->source_id)
            : null;
    }

    /**
     * Copies the documents the agent generated in this task (or run) onto the
     * draft as files, so what the text says is attached goes with the email.
     * With no ids and a text that speaks of an attachment, the last document
     * of the run goes.
     *
     * @param  list<int>  $ids
     * @return array{attached: list<string>, unknown: list<int>}
     */
    public function attachDocuments(EmailMessage $draft, AgentRun $run, array $ids, string $body): array
    {
        $runs = $run->task_id !== null
            ? AgentRun::query()->where('task_id', $run->task_id)->select('id')
            : AgentRun::query()->whereKey($run->id)->select('id');
        $mine = GeneratedDocument::query()->whereIn('agent_run_id', $runs);

        if ($ids === []) {
            if (preg_match('/\banex(o|a|os|as|ado|ada|amos)\b/iu', $body) !== 1) {
                return ['attached' => [], 'unknown' => []];
            }

            $documents = GeneratedDocument::query()->where('agent_run_id', $run->id)->latest('id')->limit(1)->get();
        } else {
            $documents = $mine->whereIn('id', $ids)->get();
        }

        $disk = (string) config('mail_ingest.disk');
        $have = $draft->attachments()->pluck('filename')->all();
        $attached = [];

        foreach ($documents as $document) {
            if (in_array($document->filename, $have, true) || ! Storage::disk($document->disk)->exists($document->path)) {
                continue;
            }

            $path = "tenants/{$draft->tenant_id}/mail/out/{$draft->id}/".Str::random(8).'-'.$document->filename;
            Storage::disk($disk)->writeStream($path, Storage::disk($document->disk)->readStream($document->path));
            EmailAttachment::query()->create([
                'email_message_id' => $draft->id,
                'filename' => $document->filename,
                'mime_type' => Storage::disk($document->disk)->mimeType($document->path) ?: 'application/octet-stream',
                'size_bytes' => $document->size_bytes,
                'disk' => $disk,
                'path' => $path,
                'ocr_status' => 'skipped',
            ]);
            $attached[] = $document->filename;
        }

        return [
            'attached' => $attached,
            'unknown' => array_values(array_diff($ids, $documents->pluck('id')->all())),
        ];
    }

    /**
     * The older open agent drafts for the same email, kept newest-first per
     * email: what `archiveDuplicates()` takes out. A person's draft is never one.
     *
     * @return Collection<int, EmailMessage>
     */
    public function duplicates(): Collection
    {
        $drafts = EmailMessage::query()->where('status', EmailStatus::Draft)->where('direction', 'outbound')
            ->whereNull('removed_at')->whereNotNull('agent_run_id')
            ->with('run:id,task_id')->orderByDesc('id')->get();

        return $drafts
            ->groupBy(fn (EmailMessage $d) => $d->mailbox_id.'|'.($d->in_reply_to !== null
                ? 'reply:'.$d->in_reply_to
                : 'new:'.($d->run->task_id ?? 'run'.$d->agent_run_id).':'.$this->recipients($d->to ?? [], $d->pending_recipient)))
            // The newest stays (a person's, if a person wrote or changed one); the older agent drafts go.
            ->flatMap(function (Collection $group) {
                $keep = $group->first(fn (EmailMessage $d) => $this->isPersons($d)) ?? $group->first();

                return $group->reject(fn (EmailMessage $d) => $d->is($keep) || $this->isPersons($d));
            })
            ->values();
    }

    /**
     * Archives a duplicate: it leaves the views and the server's Rascunhos;
     * the row stays with its text, its run and its files.
     */
    public function archive(EmailMessage $draft, MailClient $client): void
    {
        try {
            $client->removeDraftFromServer($draft);
        } catch (Throwable $e) {
            report($e);
        }

        $draft->forceFill(['email_folder_id' => null, 'imap_uid' => null, 'removed_at' => now()])->save();
    }

    /**
     * The agent drafts that went to the Rascunhos before "only when the person
     * chooses" and that no person kept or changed: what `returnToProposal()` takes back.
     *
     * @return Collection<int, EmailMessage>
     */
    public function filedProposals(): Collection
    {
        return EmailMessage::query()->proposals()->where('direction', 'outbound')->whereNull('removed_at')
            ->where(fn (Builder $q) => $q->whereNotNull('email_folder_id')->orWhereNotNull('imap_uid'))
            ->orderBy('id')->get();
    }

    /**
     * Agent drafts whose run is gone (runs deleted, the link set to null):
     * written here (no copy of a server email, `raw_path`), by no person, and
     * not kept or changed by one. Without their run they cannot show as an
     * agent's proposal, so `mail:return-agent-drafts` archives them.
     *
     * @return Collection<int, EmailMessage>
     */
    public function orphans(): Collection
    {
        return EmailMessage::query()->where('status', EmailStatus::Draft)->where('direction', 'outbound')->whereNull('removed_at')
            ->whereNull('agent_run_id')->whereNull('written_by_user_id')->whereNull('kept_at')->whereNull('edited_by_person_at')
            ->whereNull('raw_path')->orderBy('id')->get();
    }

    /**
     * Back to a proposal: out of the Rascunhos and off the server; the row
     * stays with its text, its run and its files, on the email and in the
     * conversation, for the person to keep, review and send, or discard.
     */
    public function returnToProposal(EmailMessage $draft, MailClient $client): void
    {
        try {
            $client->removeDraftFromServer($draft);
        } catch (Throwable $e) {
            report($e);
        }

        $draft->forceFill(['email_folder_id' => null, 'imap_uid' => null])->save();
    }

    /**
     * @return Builder<EmailMessage>
     */
    private function open(Mailbox $mailbox): Builder
    {
        return EmailMessage::query()->where('mailbox_id', $mailbox->id)->where('direction', 'outbound')
            ->where('status', EmailStatus::Draft)->whereNull('removed_at');
    }

    /**
     * @param  list<string>  $to
     */
    private function recipients(array $to, ?string $recipient): string
    {
        $addresses = collect($to)->map(fn ($a) => Str::lower(trim((string) $a)))->filter()->unique()->sort()->implode(',');

        return $addresses !== '' ? $addresses : 'to:'.Str::lower(trim((string) $recipient));
    }
}
