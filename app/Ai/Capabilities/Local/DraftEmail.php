<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\FactCheck;
use App\Email\AgentDrafts;
use App\Enums\AutonomyLevel;
use App\Enums\EmailStatus;
use App\Jobs\PushDraftToServer;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A new email (a follow-up, a first contact) left as a draft for the person
 * to review and send, shown in the conversation as an email card
 * (docs/DECISOES.md, "Email proposto na conversa"). It goes to the person's
 * own mailbox, or else to the agent's; nothing leaves the company. An address
 * that no source of the run has is left out, never guessed. Drafting again to
 * the same people in the same conversation rewrites the open draft.
 */
final class DraftEmail extends LocalCapability
{
    public function __construct(private readonly FactCheck $facts) {}

    public function key(): string
    {
        return 'email.draft';
    }

    public function name(): string
    {
        return 'Preparar email';
    }

    public function description(): string
    {
        return 'Prepara um email novo (seguimento, primeiro contacto, envio de proposta) como rascunho, para a pessoa rever e enviar; aparece na conversa como cartão de email. Não envia. '
            .'Usa sempre esta ferramenta quando propões um email, em vez de escrever o email no texto da resposta. Para responder a um email recebido usa email.draft_reply. '
            .'Só põe endereços que aparecem numa fonte (ERP, emails, conhecimento, mensagens); se não tens o endereço, deixa "to" vazio e indica o nome em "recipient".';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipient' => $schema->string()->description('A quem se escreve, como se diz (ex.: «Dra. Natália Saide, Fundação AZUL»). Mostrado quando falta o endereço.'),
            'to' => $schema->array()->items($schema->string())->description('Endereços de destino, tirados de uma fonte. Vazio se não os tens.'),
            'cc' => $schema->array()->items($schema->string()),
            'subject' => $schema->string()->required(),
            'body' => $schema->string()->description('Corpo em texto simples, em português, pronto a enviar (com saudação e despedida).')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'recipient' => 'nullable|string|max:255',
            'to' => 'nullable|array|max:20',
            'to.*' => 'string|max:255',
            'cc' => 'nullable|array|max:20',
            'cc.*' => 'string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:50000',
        ])->validate();

        $mailbox = $this->mailboxFor($context);

        if ($mailbox === null) {
            return CapabilityResult::error('não há caixa de email onde deixar o rascunho: a pessoa não ligou a sua caixa e tu não tens caixa própria. Escreve o email na resposta e diz-lhe que ligue a caixa em Email.');
        }

        [$to, $leftOutTo] = $this->grounded($data['to'] ?? [], $context);
        [$cc, $leftOutCc] = $this->grounded($data['cc'] ?? [], $context);
        $leftOut = [...$leftOutTo, ...$leftOutCc];
        $recipient = filled($data['recipient'] ?? null) ? Str::limit(trim((string) $data['recipient']), 250) : null;
        $drafts = app(AgentDrafts::class);

        // One open draft per email: the same people in the same conversation rewrite it (docs/DECISOES.md, "Um só rascunho por email").
        $open = $drafts->newEmail($mailbox, $context->run, $to, $recipient);

        if ($open !== null && $drafts->isPersons($open)) {
            return CapabilityResult::data([
                'draft_id' => $open->id,
                'changed' => false,
                'next' => 'Já há um rascunho deste email para as mesmas pessoas que uma pessoa alterou; não o substituí nem criei outro. Diz à pessoa, em poucas linhas, o que mudarias no rascunho dela.',
            ]);
        }

        $fields = ['to' => $to, 'cc' => $cc, 'subject' => $data['subject'], 'text_body' => $data['body'], 'pending_recipient' => $recipient];

        if ($open !== null) {
            $draft = $drafts->rewrite($open, $context->run, $fields);
        } else {
            $messageId = '<'.Str::uuid().'@'.(Str::after($mailbox->address, '@') ?: 'micomoc.local').'>';
            $draft = EmailMessage::query()->create([
                ...$fields,
                'mailbox_id' => $mailbox->id,
                'direction' => 'outbound',
                'from_address' => $mailbox->address,
                'from_name' => $mailbox->display_name,
                'status' => EmailStatus::Draft,
                'agent_run_id' => $context->run->id,
                'message_id_header' => $messageId,
                'dedup_hash' => hash('sha256', $messageId.'|'.$mailbox->id),
                'read_at' => now(),
            ]);
        }

        // Into the Rascunhos on the server too, for review in any email client.
        if (filled($mailbox->imap_host)) {
            PushDraftToServer::dispatch($draft->tenant_id, $draft->id);
        }

        return CapabilityResult::data(array_filter([
            'draft_id' => $draft->id,
            'mailbox' => $mailbox->address,
            'to' => $to,
            'cc' => $cc,
            'subject' => $data['subject'],
            'left_out' => $leftOut !== [] ? $leftOut : null,
            'updated' => $open !== null ? 'Já havia um rascunho deste email para as mesmas pessoas nesta conversa: actualizei esse, não há outro.' : null,
            'next' => 'O rascunho aparece na conversa como cartão de email, com Para, Assunto e texto, e a pessoa revê e envia daí. Não repitas o email na resposta: diz numa ou duas linhas o que preparaste'
                .($to === [] ? ', que falta o endereço do destinatário e pergunta-o' : '')
                .($leftOut !== [] ? ' (deixei de fora '.implode(', ', $leftOut).', que não aparece em nenhuma fonte)' : '')
                .'. Ninguém envia sem ordem da pessoa.',
        ], fn ($value) => $value !== null));
    }

    public function summarise(array $arguments): string
    {
        return 'Rascunho de email: '.($arguments['subject'] ?? '');
    }

    /**
     * The person's own mailbox (the draft is theirs to send), else the agent's.
     */
    private function mailboxFor(CapabilityContext $context): ?Mailbox
    {
        $person = $context->run->task->user ?? $context->run->requestedBy;

        $own = $person !== null
            ? Mailbox::query()->ownedBy($person)->orderBy('id')->get()->sortByDesc(fn (Mailbox $m) => $m->canSend())->first()
            : null;

        return $own ?? Mailbox::query()->where('agent_id', $context->agent->id)->where('kind', Mailbox::AGENT)->first();
    }

    /**
     * The addresses that are addresses and appear in a source of the run, and
     * those left out.
     *
     * @param  list<string>  $addresses
     * @return array{0: list<string>, 1: list<string>}
     */
    private function grounded(array $addresses, CapabilityContext $context): array
    {
        $clean = collect($addresses)->map(fn ($a) => Str::lower(trim((string) $a)))->filter()->unique()->values();
        $valid = $clean->filter(fn (string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false)->values();
        $unknown = $valid->isEmpty() ? [] : $this->facts->ungrounded($valid->implode("\n"), $context->run);

        $kept = $valid->reject(fn (string $a) => in_array($a, $unknown, true))->values()->all();

        return [$kept, $clean->diff($kept)->values()->all()];
    }
}
