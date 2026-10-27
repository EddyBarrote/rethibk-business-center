<?php

use App\Email\Acknowledgement;
use App\Jobs\AcknowledgeEmail;
use App\Mail\AgentMessage;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// docs/DECISOES.md, "Confirmação automática de recepção".

beforeEach(function () {
    Mail::fake();
    $this->tenant = Tenant::factory()->create(['name' => 'Rethink']);

    [$this->owner, $this->geral] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create(['email' => 'barrote@rethink.co.mz']);
        $geral = MailboxOwner::factory()->create(['user_id' => $owner->id])->mailbox;
        $geral->forceFill(['address' => 'geral@rethink.co.mz', 'kind' => Mailbox::PERSON, 'auto_ack' => true])->save();

        return [$owner, $geral];
    });
});

function inbound(array $attributes = []): EmailMessage
{
    return EmailMessage::factory()->create([
        'mailbox_id' => test()->geral->id,
        'from_address' => 'edy@cliente.co.mz',
        'from_name' => 'Edy Barrote',
        'subject' => 'Pedido de cotação',
        'classification' => 'client_rfq',
        'thread_id' => EmailThread::factory()->create(['mailbox_id' => test()->geral->id])->id,
        ...$attributes,
    ]);
}

it('answers once, from the company mailbox, that the email arrived', function () {
    asTenant($this->tenant, function () {
        $email = inbound();
        $sent = app(Acknowledgement::class)->send($email);

        expect($sent)->not->toBeNull()
            ->and($sent->subject)->toBe('Re: Pedido de cotação')
            ->and($sent->text_body)->toContain('Olá Edy Barrote,')->toContain('«Pedido de cotação»')->toContain('Rethink')
            ->and($sent->in_reply_to)->toBe($email->message_id_header);

        // The same email, or another in the same conversation: no second confirmation.
        expect(app(Acknowledgement::class)->send($email->fresh()))->toBeNull()
            ->and(app(Acknowledgement::class)->skipReason(inbound(['thread_id' => $email->thread_id])))->toBe('a conversa já foi confirmada');
    });

    Mail::assertSent(AgentMessage::class, 1);
    Mail::assertSent(AgentMessage::class, fn (AgentMessage $mail) => $mail->hasTo('edy@cliente.co.mz'));
});

it('does not answer machines, colleagues, other kinds of email, old email, or when it is off', function () {
    asTenant($this->tenant, function () {
        $ack = app(Acknowledgement::class);

        expect($ack->skipReason(inbound(['from_address' => 'no-reply@emails.whop.com'])))->toBe('o remetente é automático')
            ->and($ack->skipReason(inbound(['from_address' => 'kmacatange@rethink.co.mz'])))->toBe('o remetente é da empresa')
            ->and($ack->skipReason(inbound(['classification' => 'supplier_invoice'])))->toBe('este tipo de email não é confirmado')
            ->and($ack->skipReason(inbound(['received_at' => now()->subDays(3)])))->toBe('chegou há mais de dois dias');

        $this->geral->forceFill(['auto_ack' => false])->save();
        expect($ack->skipReason(inbound()))->toBe('a caixa não confirma a recepção');
    });

    Mail::assertNothingSent();
});

it('uses the text and the kinds of email the admin chose', function () {
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/mailboxes/{$this->geral->id}"), [
        'address' => 'geral@rethink.co.mz', 'display_name' => 'Rethink', 'imap_host' => 'imap.example.test',
        'auto_ack' => true, 'auto_ack_categories' => ['lead'], 'auto_ack_body' => 'Caro {nome}, obrigado. {empresa}',
    ])->assertSessionHasNoErrors();

    asTenant($this->tenant, function () {
        $ack = app(Acknowledgement::class);
        expect($ack->skipReason(inbound()))->toBe('este tipo de email não é confirmado')
            ->and($ack->send(inbound(['classification' => 'lead', 'from_name' => null]))->text_body)->toBe('Caro, obrigado. Rethink');
    });
});

it('is sent after the triage classifies an email of a mailbox that confirms', function () {
    Queue::fake();
    $email = asTenant($this->tenant, fn () => inbound(['classification' => null, 'sent_to_triage_at' => now()]));

    asTenant($this->tenant, fn () => runCapability(templateAgent('triage'), 'email.classify', [
        'email_id' => $email->id, 'category' => 'client_rfq', 'confidence' => 0.95, 'priority' => 'normal', 'summary' => 'Pedido de cotação.',
    ]));

    Queue::assertPushed(AcknowledgeEmail::class, fn (AcknowledgeEmail $job) => $job->emailId === $email->id);
});
