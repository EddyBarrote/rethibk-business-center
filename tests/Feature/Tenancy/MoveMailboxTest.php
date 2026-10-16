<?php

use App\Enums\MailboxStatus;
use App\Models\Agent;
use App\Models\EmailAttachment;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

// Moving a real mailbox to another tenant (docs/DECISOES.md, "Rethink Technologies como tenant").

it('moves a mailbox with its history to another tenant and lets go of what belonged to the old one', function () {
    Storage::fake('local');
    $from = Tenant::factory()->create(['slug' => 'demo']);
    $to = Tenant::factory()->create(['slug' => 'real']);

    [$mailbox, $message, $attachment, $task] = asTenant($from, function () {
        $owner = User::factory()->owner()->create();
        $agent = Agent::factory()->create();
        $mailbox = Mailbox::factory()->personal()->create(['address' => 'geral@empresa.test', 'imap_host' => 'imap.empresa.test', 'imap_password' => 'segredo-imap', 'imap_last_uid' => 483]);
        MailboxOwner::query()->create(['mailbox_id' => $mailbox->id, 'user_id' => $owner->id]);
        MailboxReader::query()->create(['mailbox_id' => $mailbox->id, 'agent_id' => $agent->id, 'processes_new' => true]);
        $folder = EmailFolder::query()->create(['mailbox_id' => $mailbox->id, 'path' => 'INBOX', 'name' => 'Entrada', 'role' => 'inbox', 'last_uid' => 483]);
        $thread = EmailThread::query()->create(['mailbox_id' => $mailbox->id, 'subject_normalized' => 'pedido', 'message_count' => 1]);
        $message = EmailMessage::factory()->create(['mailbox_id' => $mailbox->id, 'email_folder_id' => $folder->id, 'thread_id' => $thread->id, 'routed_to_user_id' => $owner->id, 'raw_path' => "tenants/{$mailbox->tenant_id}/mail/raw.eml"]);
        $path = "tenants/{$mailbox->tenant_id}/mail/cv.pdf";
        Storage::disk('local')->put($path, 'pdf');
        Storage::disk('local')->put($message->raw_path, 'raw');
        $attachment = EmailAttachment::factory()->create(['email_message_id' => $message->id, 'path' => $path]);
        $task = Task::factory()->create(['source_type' => EmailMessage::class, 'source_id' => $message->id]);
        $owner->forceFill(['last_mailbox_id' => $mailbox->id])->save();

        return [$mailbox, $message, $attachment, $task];
    });

    $this->artisan('tenant:move-mailbox', ['address' => 'geral@empresa.test', 'from' => 'demo', 'to' => 'real', '--active' => true])->assertSuccessful();

    asTenant($from, function () use ($task) {
        expect(Mailbox::query()->count())->toBe(0)
            ->and(EmailMessage::query()->count())->toBe(0)
            ->and(MailboxOwner::query()->count())->toBe(0)
            ->and(MailboxReader::query()->count())->toBe(0)
            ->and($task->fresh()->source_id)->toBeNull()
            ->and(User::query()->whereNotNull('last_mailbox_id')->count())->toBe(0);
    });

    asTenant($to, function () use ($mailbox, $message, $attachment) {
        $moved = Mailbox::query()->findOrFail($mailbox->id);
        $copy = EmailMessage::query()->findOrFail($message->id);
        $file = EmailAttachment::query()->findOrFail($attachment->id);

        expect($moved->status)->toBe(MailboxStatus::Active)
            ->and($moved->imap_password)->toBe('segredo-imap')
            ->and($moved->imap_last_uid)->toBe(483)
            ->and(EmailFolder::query()->where('mailbox_id', $moved->id)->value('last_uid'))->toBe(483)
            ->and(EmailThread::query()->where('mailbox_id', $moved->id)->count())->toBe(1)
            ->and($copy->routed_to_user_id)->toBeNull()
            ->and($copy->raw_path)->toBe("tenants/{$moved->tenant_id}/mail/raw.eml")
            ->and($file->path)->toBe("tenants/{$moved->tenant_id}/mail/cv.pdf");
        Storage::disk('local')->assertExists($file->path);
    });
});

it('refuses to move a mailbox that is not in the old tenant', function () {
    Tenant::factory()->create(['slug' => 'demo']);
    Tenant::factory()->create(['slug' => 'real']);

    $this->artisan('tenant:move-mailbox', ['address' => 'nao@existe.test', 'from' => 'demo', 'to' => 'real'])->assertFailed();
});
