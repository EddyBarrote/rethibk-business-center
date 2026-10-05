<?php

use App\Ai\Capabilities\CapabilityRegistry;
use App\Email\EmailPrompt;
use App\Email\MailboxMailer;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Models\Agent;
use App\Models\Department;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Personal mailboxes (docs/DECISOES.md, "Caixas de email por pessoa").

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->ana, $this->rui, $this->finance] = asTenant($this->tenant, function () {
        $finance = Department::factory()->create();

        return [
            User::factory()->owner()->create(),
            User::factory()->create(['role' => 'member', 'department_id' => $finance->id]),
            User::factory()->create(['role' => 'member']),
            Agent::factory()->create(['key' => 'finance', 'department_id' => $finance->id]),
        ];
    });
});

function connectMailbox(object $test, User $who, array $data = []): void
{
    $test->actingAs($who)->post(tenantUrl($test->tenant, 'mailboxes'), [
        'address' => 'ana@empresa.test',
        'display_name' => 'Ana',
        'imap_host' => 'imap.empresa.test', 'imap_port' => 993, 'imap_username' => 'ana@empresa.test', 'imap_password' => 'segredo-imap', 'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.empresa.test', 'smtp_port' => 465, 'smtp_username' => 'ana@empresa.test', 'smtp_password' => 'segredo-smtp', 'smtp_encryption' => 'ssl',
        ...$data,
    ])->assertSessionHasNoErrors()->assertRedirect();
}

it('lets a person connect their own mailbox, read by the agent of their area, with the credentials encrypted', function () {
    connectMailbox($this, $this->ana);

    asTenant($this->tenant, function () {
        $mailbox = Mailbox::query()->where('address', 'ana@empresa.test')->sole();

        expect($mailbox->isPersonal())->toBeTrue()
            ->and($mailbox->isOwnedBy($this->ana))->toBeTrue()
            ->and($mailbox->processor()?->id)->toBe($this->finance->id)
            ->and($mailbox->imap_password)->toBe('segredo-imap')
            ->and(DB::table('mailboxes')->where('id', $mailbox->id)->value('imap_password'))->not->toContain('segredo');
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, 'mailboxes'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Mailboxes/Index')->has('mailboxes', 1)->where('mailboxes.0.readers.0.processes_new', true));
    $this->actingAs($this->rui)->get(tenantUrl($this->tenant, 'mailboxes'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('mailboxes', 0));
});

it('lets only owners change a mailbox, and those who manage every mailbox name the owners', function () {
    connectMailbox($this, $this->ana);
    $mailbox = asTenant($this->tenant, fn () => Mailbox::query()->where('address', 'ana@empresa.test')->sole());
    $update = ['address' => 'ana@empresa.test', 'display_name' => 'Ana Sitoe', 'readers' => [], 'processor' => null];

    $this->actingAs($this->rui)->put(tenantUrl($this->tenant, "mailboxes/{$mailbox->id}"), $update)->assertForbidden();
    $this->actingAs($this->ana)->put(tenantUrl($this->tenant, "mailboxes/{$mailbox->id}"), [...$update, 'owners' => [$this->rui->id]])->assertRedirect();

    // An owner cannot hand the mailbox to someone else; the readers are theirs to choose.
    asTenant($this->tenant, fn () => expect($mailbox->isOwnedBy($this->ana))->toBeTrue()
        ->and($mailbox->isOwnedBy($this->rui))->toBeFalse()
        ->and(MailboxReader::query()->where('mailbox_id', $mailbox->id)->count())->toBe(0));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "mailboxes/{$mailbox->id}"), [...$update, 'owners' => [$this->ana->id, $this->rui->id]])->assertRedirect();
    asTenant($this->tenant, fn () => expect($mailbox->isOwnedBy($this->rui))->toBeTrue());
});

it('refuses a person the matrix does not let connect mailboxes', function () {
    $this->rui->forceFill(['permission_overrides' => [Permission::ConnectOwnMailboxes->value => false]])->save();

    $this->actingAs($this->rui)->get(tenantUrl($this->tenant, 'mailboxes'))->assertForbidden();
    $this->actingAs($this->rui)->post(tenantUrl($this->tenant, 'mailboxes'), ['address' => 'rui@empresa.test', 'display_name' => 'Rui'])->assertForbidden();
});

it('keeps a personal mailbox private, even from administrators', function () {
    $email = asTenant($this->tenant, function () {
        $owned = MailboxOwner::factory()->create(['user_id' => $this->ana->id]);

        return EmailMessage::factory()->create(['mailbox_id' => $owned->mailbox_id, 'subject' => 'Pessoal']);
    });

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox/{$email->id}"))->assertOk();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "inbox/{$email->id}"))->assertForbidden();
});

it('lets an agent read a personal mailbox only when its owners chose it, and draft replies there without sending', function () {
    [$email, $other] = asTenant($this->tenant, function () {
        $owned = MailboxOwner::factory()->create(['user_id' => $this->ana->id]);
        $email = EmailMessage::factory()->create(['mailbox_id' => $owned->mailbox_id, 'subject' => 'Proposta']);

        return [$email, Agent::factory()->create()];
    });

    asTenant($this->tenant, function () use ($email, $other) {
        expect(runCapability($this->finance, 'email.read', ['email_id' => $email->id])->ok)->toBeFalse();

        MailboxReader::factory()->create(['mailbox_id' => $email->mailbox_id, 'agent_id' => $this->finance->id]);

        expect(runCapability($this->finance, 'email.read', ['email_id' => $email->id])->ok)->toBeTrue()
            ->and(runCapability($other, 'email.read', ['email_id' => $email->id])->ok)->toBeFalse();

        // The reply is a draft in Ana's mailbox, for her to send.
        runCapability($this->finance, 'email.draft_reply', ['email_id' => $email->id, 'body' => 'Obrigada, vamos analisar.']);
        $draft = EmailMessage::query()->where('status', EmailStatus::Draft)->sole();
        expect($draft->mailbox_id)->toBe($email->mailbox_id);

        // The agent has no mailbox of its own, so it cannot send at all.
        expect(runCapability($this->finance, 'comms.send_email', ['to' => ['cliente@fora.test'], 'subject' => 'Re: Proposta', 'body' => 'Olá'])->ok)
            ->toBeFalse();

        // Reading a person's mailbox gives the agent the email tools, never sending.
        expect(CapabilityRegistry::MAILBOX_READER)->not->toContain('comms.send_email');
    });

    $draft = asTenant($this->tenant, fn () => EmailMessage::query()->where('status', EmailStatus::Draft)->sole());
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "inbox/{$draft->id}/send"), ['to' => ['cliente@fora.test'], 'subject' => 'Re: Proposta', 'body' => 'Obrigada.'])
        ->assertSessionHas('success');
});

it('never sends from a person mailbox except for one of its owners', function () {
    asTenant($this->tenant, function () {
        $mailbox = MailboxOwner::factory()->create(['user_id' => $this->ana->id])->mailbox;
        $mailer = app(MailboxMailer::class);

        expect(fn () => $mailer->send($mailbox, ['x@fora.test'], [], 'Olá', 'Texto'))->toThrow(RuntimeException::class)
            ->and(fn () => $mailer->send($mailbox, ['x@fora.test'], [], 'Olá', 'Texto', sender: $this->rui))->toThrow(RuntimeException::class);

        expect($mailer->send($mailbox, ['x@fora.test'], [], 'Olá', 'Texto', sender: $this->ana)->status)->toBe(EmailStatus::Sent);
    });
});

it('hands new email in a personal mailbox to the agent its owners chose, as their helper', function () {
    asTenant($this->tenant, function () {
        $mailbox = MailboxOwner::factory()->create(['user_id' => $this->ana->id])->mailbox;
        MailboxReader::factory()->create(['mailbox_id' => $mailbox->id, 'agent_id' => $this->finance->id, 'processes_new' => true]);
        $email = EmailMessage::factory()->create(['mailbox_id' => $mailbox->id, 'subject' => 'Factura 12']);

        expect($mailbox->processor()?->id)->toBe($this->finance->id)
            ->and(EmailPrompt::forPerson($email))->toContain($this->ana->email)->toContain('Nunca envies');
    });
});
