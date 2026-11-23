<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Skills\AgentSkills;
use App\Documents\Brand;
use App\Documents\DocumentType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Number;

/**
 * Loads one of the agent's skills into the conversation, as Claude does with
 * Agent Skills: the prompt only lists names and descriptions, the agent reads
 * the instructions when the work calls for them. Given to agents with skills.
 */
final class LoadSkill extends LocalCapability
{
    /** Instructions written for a runtime that runs code (docs/DECISOES.md, "Contas sobre tabelas anexadas"). */
    private const RUNS_SCRIPTS = '/```(?:bash|sh|shell|python|py)\b|\bpython3?\s+\S+\.py\b|\bscripts\/\S+\.(?:py|sh|js)\b/i';

    public function __construct(private readonly AgentSkills $skills) {}

    public function key(): string
    {
        return 'skills.load';
    }

    public function name(): string
    {
        return 'Carregar skill';
    }

    public function description(): string
    {
        return 'Lê as instruções completas de uma das tuas skills (e a lista dos ficheiros dela). Usa antes de fazer o trabalho a que a skill se aplica.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['skill' => $schema->string()->description('A chave da skill, como aparece na lista das tuas skills.')->required()];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $skill = $this->skills->find($context->agent, (string) ($arguments['skill'] ?? ''));

        if ($skill === null) {
            return CapabilityResult::error('não tens nenhuma skill com essa chave. Vê a lista das tuas skills nas instruções.');
        }

        $files = collect($skill->attachments())
            ->map(fn (array $file) => "- {$file['filename']} (".Number::fileSize($file['size']).')'.($file['content'] === null ? ', sem texto legível' : ''))
            ->implode("\n");

        // The version goes into the run's log, so the run shows which instructions it followed (docs/DECISOES.md, "Latência, taxa de erro e versões das skills").
        $version = $skill->versionNumber();

        return CapabilityResult::text(
            "# Skill: {$skill->displayName()} ({$skill->key}".($version !== null ? ", versão {$version}" : '').")\n\n".Brand::current()->fill($skill->body())
            .($files !== '' ? "\n\n## Ficheiros desta skill\nLê-os com skills.read_file quando precisares:\n{$files}" : '')
            .(preg_match(self::RUNS_SCRIPTS, $skill->body()) ? "\n\n## Scripts\nAs instruções falam em correr scripts, que a plataforma não corre. Faz o que o script faria com as tuas ferramentas: as contas sobre ficheiros CSV ou Excel com files.analyze (percorre o ficheiro todo; filtra, agrupa e soma) ou, para o que ela não faz, com code.run (Python numa caixa isolada, se o tiveres), os ficheiros finais com documents.generate. Nunca estimes nem inventes os números que o script calcularia; se uma conta não for possível, diz qual e porquê." : '')
            .(DocumentType::config($skill->body()) !== [] ? "\n\nÉ um tipo de documento: gera o ficheiro com documents.generate e skill: «{$skill->key}» (modelo, estilo e imagens vêm daqui; as que faltarem a plataforma pede à pessoa)." : ''),
        );
    }
}
