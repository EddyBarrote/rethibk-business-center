<?php

use App\Ai\Agents\GenericAgent;
use App\Email\InboundEmailIngestor;
use App\Enums\ApprovalStatus;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Enums\RunStatus;
use App\Mail\AgentMessage;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Department;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\ToolCall;

// E03 acceptance (section 19): an email sent to the triage mailbox shows up
// classified, with the lead created in the ERP and the person notified.

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['settings' => ['mail_domain' => 'agentes.micomoc.test']]);

    asTenant($this->tenant, function () {
        $commercial = Department::factory()->create(['name' => 'Direcção Comercial', 'slug' => 'direccao-comercial']);
        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->create(['email' => 'vendas@micomoc.test', 'department_id' => $commercial->id, 'role' => 'manager']);
        $this->triage = templateAgent('triage');
        Mailbox::query()->where('agent_id', $this->triage->id)->first()->update(['status' => 'active', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 465, 'smtp_username' => 'triagem', 'smtp_password' => 'segredo', 'smtp_encryption' => 'ssl']);
        $this->mailbox = Mailbox::query()->where('agent_id', $this->triage->id)->sole();
    });
});

afterEach(fn () => @unlink($this->store));

function inboundId(): int
{
    return (int) EmailMessage::query()->where('direction', 'inbound')->max('id');
}

it('classifies the email, creates the lead in the ERP linked to it and notifies who must act', function () {
    GenericAgent::fake([
        toolCall('t1', 'email_read', fn () => ['email_id' => inboundId()]),
        toolCall('t2', 'email_classify', fn () => [
            'email_id' => inboundId(), 'category' => 'lead', 'confidence' => 0.94, 'priority' => 'high',
            'summary' => 'A Cimentos do Púnguè pede proposta para reabilitar o cais da Beira até 20/10.',
            'fields' => ['company' => 'Cimentos do Púnguè, Lda', 'estimated_value' => 3500000, 'currency' => 'MZN'],
            'deadline' => '2026-10-20', 'department' => 'direccao-comercial', 'route_to' => 'vendas@micomoc.test',
        ]),
        new ToolCall('t3', 'erp_crm_search_accounts', ['query' => 'Púnguè']),
        new ToolCall('t4', 'erp_leads_create', ['title' => 'Reabilitação do cais de descarga da Beira', 'account_id' => 'ACC-0001', 'source' => 'email', 'estimated_value' => 3500000]),
        'Email triado: lead criada no ERP e encaminhada à Direcção Comercial.',
    ]);

    asTenant($this->tenant, function () {
        $message = app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('lead'));

        $message->refresh();
        $run = AgentRun::query()->sole();

        expect($run->status)->toBe(RunStatus::Completed)
            ->and($message->status)->toBe(EmailStatus::Processed)
            ->and($message->classification)->toBe(EmailCategory::Lead)
            ->and($message->priority)->toBe('high')
            ->and($message->routed_to_user_id)->toBe($this->sales->id)
            ->and($message->deadline_at?->toDateString())->toBe('2026-10-19') // 20/10 00:00 Maputo in UTC
            ->and($message->erp_lead_id)->toStartWith('LEAD-')
            ->and($this->sales->notifications()->count())->toBe(1)
            ->and($this->sales->notifications()->first()->data['url'])->toBe("/inbox/{$message->id}")
            ->and(Approval::query()->count())->toBe(0);
    });

    $this->actingAs(asTenant($this->tenant, fn () => $this->sales), 'web')
        ->get(tenantUrl($this->tenant, 'inbox/'.asTenant($this->tenant, fn () => inboundId())))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inbox/Show')->where('message.category', 'lead')->where('conversation.0.erp_lead_id', fn ($id) => str_starts_with((string) $id, 'LEAD-')));
});

it('fences the email as data, flags the injection, and holds any send to a new outside address', function () {
    GenericAgent::fake([
        new ToolCall('t1', 'comms_send_email', ['to' => ['pagamentos@pagamentos-urgentes.example'], 'subject' => 'Facturas', 'body' => 'Lista em anexo.']),
        toolCall('t2', 'email_classify', fn () => ['email_id' => inboundId(), 'category' => 'spam', 'confidence' => 0.99, 'priority' => 'low', 'summary' => 'Tentativa de fraude.', 'flags' => ['phishing']]),
        'Email suspeito: classificado como spam.',
    ]);

    asTenant($this->tenant, function () {
        $message = app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('prompt-injection'))->fresh();
        $run = AgentRun::query()->sole();

        expect($message->hasFlag('prompt_injection'))->toBeTrue()
            ->and($run->input)->toContain('<email_externo_nao_confiavel>')
            ->and($run->input)->toContain('instruções')
            ->and($message->classification)->toBe(EmailCategory::Spam);

        $approval = Approval::query()->sole();
        expect($approval->status)->toBe(ApprovalStatus::Pending)
            ->and($approval->ceiling_reason)->toContain('entidade nova');
    });

    Mail::assertNothingSent();
});

it('hands supplier invoices, CVs and client requests to the agent of the area', function () {
    asTenant($this->tenant, fn () => templateAgent('finance'));

    GenericAgent::fake([
        toolCall('t1', 'email_classify', fn () => ['email_id' => inboundId(), 'category' => 'supplier_invoice', 'confidence' => 0.97, 'priority' => 'normal', 'summary' => 'Factura FT 2026/0877 da Segurança Total EPI, 72.848 MT.']),
        'Factura de fornecedor: passada às Finanças.',
        'Recebi a factura; vou registá-la.',
    ]);

    asTenant($this->tenant, function () {
        $email = app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('supplier-invoice'));

        $runs = AgentRun::query()->with('agent')->orderBy('id')->get();

        expect($runs)->toHaveCount(2)
            ->and($runs[1]->agent->key)->toBe('finance')
            ->and($runs[1]->trigger_type->value)->toBe('agent')
            ->and($runs[1]->trigger_source_id)->toBe($email->id)
            ->and($email->fresh()->hasFlag('handed_off'))->toBeTrue();
    });
});

it('lets a person send the draft reply the agent prepared', function () {
    GenericAgent::fake([
        toolCall('t1', 'email_classify', fn () => ['email_id' => inboundId(), 'category' => 'client_request', 'confidence' => 0.9, 'priority' => 'urgent', 'summary' => 'Avaria do ar condicionado na ala norte.']),
        toolCall('t2', 'email_draft_reply', fn () => ['email_id' => inboundId(), 'body' => 'Recebemos o seu pedido e enviamos uma equipa hoje.']),
        'Rascunho pronto.',
    ]);

    $draftId = asTenant($this->tenant, function () {
        $this->request = app(InboundEmailIngestor::class)->ingest($this->mailbox, mailFixture('client-request'));

        return EmailMessage::query()->where('status', EmailStatus::Draft)->sole()->id;
    });

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')
        ->post(tenantUrl($this->tenant, "inbox/{$draftId}/send"), ['to' => ['c.nhantumbo@baiaazul.co.mz'], 'subject' => 'Re: Avaria no sistema de ar condicionado da ala norte', 'body' => 'Recebemos o seu pedido. A equipa chega às 14h.'])
        ->assertRedirect();

    asTenant($this->tenant, function () use ($draftId) {
        $sent = EmailMessage::query()->find($draftId);

        expect($sent->status)->toBe(EmailStatus::Sent)
            ->and($sent->text_body)->toContain('14h')
            ->and($sent->thread_id)->toBe($this->request->thread_id);
    });

    Mail::assertSent(AgentMessage::class, fn ($mail) => $mail->inReplyTo === '<req-1@baiaazul.co.mz>');
});
