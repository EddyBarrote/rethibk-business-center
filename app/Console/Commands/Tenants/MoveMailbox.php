<?php

namespace App\Console\Commands\Tenants;

use App\Enums\MailboxStatus;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\FollowUp;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Moves a mailbox, with its folders, conversations, messages and attachments,
 * from one tenant to another (docs/DECISOES.md, "Rethink Technologies como
 * tenant"). The address is unique across tenants, so a mailbox cannot exist
 * twice: it changes hands, credentials and all, and keeps its IMAP position so
 * nothing already read is handed to the agents again.
 *
 * What only made sense in the old tenant is let go: owners and readers, the
 * agent runs, people and departments on each message, and the old tenant's
 * tasks, runs and follow-ups pointing at the messages. The mailbox arrives
 * disabled unless --active is given, without owner or readers.
 */
#[Signature('tenant:move-mailbox {address : Endereço da caixa} {from : Slug do tenant de origem} {to : Slug do tenant de destino} {--active : Deixa a caixa activa no destino}')]
#[Description('Muda uma caixa de correio, com o histórico, de um tenant para outro')]
class MoveMailbox extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $from = Tenant::query()->where('slug', (string) $this->argument('from'))->first();
        $to = Tenant::query()->where('slug', (string) $this->argument('to'))->first();

        if ($from === null || $to === null || $from->is($to)) {
            $this->components->error('Indique dois tenants diferentes que existam.');

            return self::FAILURE;
        }

        $address = mb_strtolower((string) $this->argument('address'));
        $mailbox = $tenants->run($from, fn () => Mailbox::query()->where('address', $address)->first());

        if ($mailbox === null) {
            $this->components->error("A caixa {$address} não existe em {$from->slug}.");

            return self::FAILURE;
        }

        $counts = DB::transaction(fn () => $tenants->run($from, fn () => $this->move($mailbox, $from, $to)));

        $tenants->run($to, function () use ($mailbox, $to) {
            Mailbox::query()->whereKey($mailbox->id)->update([
                'status' => $this->option('active') ? MailboxStatus::Active : MailboxStatus::Disabled,
                'agent_id' => null,
                'last_error' => null,
            ]);
            AuditLog::record(null, 'mailbox.moved_in', ['address' => $mailbox->address, 'from' => $mailbox->tenant_id], subject: Mailbox::query()->find($mailbox->id));

            return $to;
        });

        $this->components->info("{$address} passou de {$from->slug} para {$to->slug}: {$counts['messages']} mensagens, {$counts['attachments']} anexos.");

        return self::SUCCESS;
    }

    /**
     * Runs as the old tenant, so every query below sees only its rows.
     *
     * @return array{messages: int, attachments: int}
     */
    private function move(Mailbox $mailbox, Tenant $from, Tenant $to): array
    {
        $messages = EmailMessage::query()->where('mailbox_id', $mailbox->id)->pluck('id');
        $attachments = EmailAttachment::query()->whereIn('email_message_id', $messages)->get(['id', 'disk', 'path']);

        // What points at the messages from the old tenant's own work.
        Task::query()->where('source_type', EmailMessage::class)->whereIn('source_id', $messages)->update(['source_type' => null, 'source_id' => null]);
        AgentRun::query()->where('trigger_source_type', EmailMessage::class)->whereIn('trigger_source_id', $messages)->update(['trigger_source_type' => null, 'trigger_source_id' => null]);
        FollowUp::query()->where('subject_type', EmailMessage::class)->whereIn('subject_id', $messages)->update(['subject_type' => null, 'subject_id' => null]);
        WorkflowRun::query()->whereIn('email_message_id', $messages)->update(['email_message_id' => null]);
        Tender::query()->whereIn('email_message_id', $messages)->update(['email_message_id' => null]);
        User::query()->where('last_mailbox_id', $mailbox->id)->update(['last_mailbox_id' => null]);
        MailboxOwner::query()->where('mailbox_id', $mailbox->id)->delete();
        MailboxReader::query()->where('mailbox_id', $mailbox->id)->delete();

        // The files follow under the new tenant's folder.
        foreach ($attachments as $attachment) {
            EmailAttachment::query()->whereKey($attachment->id)->update(['tenant_id' => $to->id, 'path' => $this->copy((string) $attachment->disk, (string) $attachment->path, $from, $to)]);
        }

        EmailMessage::query()->where('mailbox_id', $mailbox->id)->whereNotNull('raw_path')->get(['id', 'raw_path'])
            ->each(fn (EmailMessage $message) => EmailMessage::query()->whereKey($message->id)->update(['raw_path' => $this->copy('local', (string) $message->raw_path, $from, $to)]));

        EmailMessage::query()->where('mailbox_id', $mailbox->id)->update([
            'tenant_id' => $to->id,
            'agent_run_id' => null,
            'routed_to_user_id' => null,
            'department_id' => null,
            'written_by_user_id' => null,
        ]);
        EmailThread::query()->where('mailbox_id', $mailbox->id)->update(['tenant_id' => $to->id]);
        EmailFolder::query()->where('mailbox_id', $mailbox->id)->update(['tenant_id' => $to->id]);
        Mailbox::query()->whereKey($mailbox->id)->update(['tenant_id' => $to->id]);

        AuditLog::record(null, 'mailbox.moved_out', ['address' => $mailbox->address, 'to' => $to->id, 'messages' => $messages->count()]);

        return ['messages' => $messages->count(), 'attachments' => $attachments->count()];
    }

    /** A copy of a stored file under the new tenant's folder; the old one stays for the old tenant's exports. */
    private function copy(string $disk, string $path, Tenant $from, Tenant $to): string
    {
        $prefix = "tenants/{$from->id}/";

        if (! str_starts_with($path, $prefix)) {
            return $path;
        }

        $target = "tenants/{$to->id}/".substr($path, strlen($prefix));
        $storage = Storage::disk($disk);

        if ($storage->exists($path) && ! $storage->exists($target)) {
            $storage->copy($path, $target);
        }

        return $target;
    }
}
