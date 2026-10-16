<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\AgentKnowledgeWriter;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Ai\Knowledge\KnowledgeRepository;
use App\Enums\AutonomyLevel;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\MessageAttachment;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * An agent files a file it received into the company's repository
 * (docs/DECISOES.md, 14.10.2026): an attachment of this conversation, or of
 * the email it is working on. Same rules as knowledge.save: marked as filed
 * by the agent and, below N3, waiting for a person's review.
 */
final class FileToKnowledge extends LocalCapability
{
    public function __construct(
        private readonly KnowledgeRepository $repository,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
        private readonly AgentKnowledgeWriter $writer,
    ) {}

    public function key(): string
    {
        return 'knowledge.file';
    }

    public function name(): string
    {
        return 'Guardar ficheiro no repositório';
    }

    public function description(): string
    {
        return 'Guarda na base de conhecimento (o repositório de ficheiros da empresa) um anexo desta conversa (anexo #id) ou do email em que estás a trabalhar (anexo de email #id), num domínio e pasta, para que fique pesquisável por pessoas e agentes. Usa-o para contratos, facturas, propostas, cadernos de encargos e outros documentos que importam depois.';
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
            'attachment_id' => $schema->integer()->description('Um anexo desta conversa (anexo #id).'),
            'email_attachment_id' => $schema->integer()->description('Um anexo do email em que estás a trabalhar (anexo de email #id).'),
            'title' => $schema->string()->description('Título, ex.: "Contrato de fornecimento Mozal 2026". Por omissão, o nome do ficheiro.'),
            'domain' => $schema->string()->description('Domínio (knowledge.browse mostra os que existem). Por omissão "Geral".'),
            'folder' => $schema->string()->description('Pasta dentro do domínio, ex.: "Clientes/Mozal/Contratos". É criada se não existir.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'attachment_id' => 'nullable|integer|required_without:email_attachment_id',
            'email_attachment_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'domain' => 'nullable|string|max:120',
            'folder' => 'nullable|string|max:255',
        ])->validate();

        $file = filled($data['attachment_id'] ?? null) ? $this->conversationFile($data['attachment_id'], $context) : $this->emailFile((int) $data['email_attachment_id'], $context);

        if ($file === null) {
            return CapabilityResult::error('Anexo não encontrado nesta conversa ou no email em que estás a trabalhar.');
        }

        $this->access->ensureDefaults();
        $agent = $context->agent;
        $domain = $this->locator->domain($data['domain'] ?? 'Geral') ?? $this->locator->domain('geral');

        if ($domain === null || ! $this->access->canOpen($agent, $domain)) {
            return CapabilityResult::error('Domínio não encontrado ou sem acesso. Veja os domínios com knowledge.browse.');
        }

        $folder = filled($data['folder'] ?? null) ? $this->locator->folder($domain, $data['folder'], create: true) : null;
        $existing = $this->repository->duplicateOf($file, $agent);

        if ($existing !== null) {
            return CapabilityResult::data(['already_there' => true, 'knowledge_item_id' => $existing->id, 'title' => $existing->title, 'note' => 'Este ficheiro já está no repositório.']);
        }

        $item = $this->repository->fromStoredFile($file, $agent, [
            'title' => $data['title'] ?? null,
            'knowledge_domain_id' => $domain->id,
            'knowledge_folder_id' => $folder?->id,
            'status' => AgentKnowledgeWriter::statusFor($agent),
        ]);

        if ($item === null) {
            return CapabilityResult::error('O ficheiro já não existe no armazenamento.');
        }

        $this->writer->announce($agent, $item, $domain);

        return CapabilityResult::data([
            'knowledge_item_id' => $item->id,
            'link' => "/knowledge/{$item->id}",
            'domain' => $domain->name,
            'folder' => $folder?->path(),
            'status' => $item->isPublished() ? 'publicado' : 'à espera de revisão por uma pessoa',
            'note' => 'O texto é lido em segundo plano; a pesquisa encontra-o daqui a pouco.',
        ]);
    }

    private function conversationFile(int $id, CapabilityContext $context): ?MessageAttachment
    {
        return $context->run->task_id === null ? null : MessageAttachment::query()
            ->ready()
            ->whereIn('task_id', app(TaskThread::class)->readableTaskIds($context->run))
            ->whereNotNull('task_message_id')
            ->find($id);
    }

    /**
     * An attachment of the email that started this run, or of the email the task came from.
     */
    private function emailFile(int $id, CapabilityContext $context): ?EmailAttachment
    {
        $email = (new EmailMessage)->getMorphClass();
        $messages = array_filter([
            $context->run->trigger_source_type === $email ? $context->run->trigger_source_id : null,
            $context->run->task?->source_type === $email ? $context->run->task->source_id : null,
        ]);

        return $messages === [] ? null : EmailAttachment::query()->whereIn('email_message_id', $messages)->find($id);
    }

    public function summarise(array $arguments): string
    {
        return 'Guardar no repositório: '.($arguments['title'] ?? (isset($arguments['attachment_id']) ? "anexo #{$arguments['attachment_id']}" : 'anexo de email #'.($arguments['email_attachment_id'] ?? '?')));
    }
}
