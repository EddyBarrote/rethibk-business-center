<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Skills\AgentSkills;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Reads a file attached to one of the agent's skills (a template, an example,
 * a price list), in pages of 12 000 characters.
 */
final class ReadSkillFile extends LocalCapability
{
    private const PAGE = 12_000;

    public function __construct(private readonly AgentSkills $skills) {}

    public function key(): string
    {
        return 'skills.read_file';
    }

    public function name(): string
    {
        return 'Ler ficheiro de skill';
    }

    public function description(): string
    {
        return 'Lê o texto de um ficheiro anexo a uma das tuas skills (modelos, exemplos, tabelas).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()->description('A chave da skill.')->required(),
            'filename' => $schema->string()->description('O nome do ficheiro, como aparece em skills.load.')->required(),
            'offset' => $schema->integer()->min(0),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $skill = $this->skills->find($context->agent, (string) ($arguments['skill'] ?? ''));

        if ($skill === null) {
            return CapabilityResult::error('não tens nenhuma skill com essa chave.');
        }

        $file = collect($skill->attachments())->firstWhere('filename', (string) ($arguments['filename'] ?? ''));

        if ($file === null) {
            return CapabilityResult::error('essa skill não tem nenhum ficheiro com esse nome.');
        }

        if ($file['content'] === null) {
            return CapabilityResult::text("O ficheiro {$file['filename']} não tem texto legível.");
        }

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $chunk = mb_substr($file['content'], $offset, self::PAGE);
        $more = mb_strlen($file['content']) > $offset + self::PAGE ? "\n[continua: usa offset ".($offset + self::PAGE).']' : '';

        return CapabilityResult::text("{$file['filename']}\n\n{$chunk}{$more}");
    }
}
