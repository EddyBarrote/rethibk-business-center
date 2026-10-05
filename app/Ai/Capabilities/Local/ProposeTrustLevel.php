<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The Chief of Staff proposes raising or lowering an agent's trust level from
 * its track record; the CEO or an administrator confirms (docs/DECISOES.md,
 * realinhamento L11). Calling it always becomes an approval.
 */
final class ProposeTrustLevel extends LocalCapability
{
    public function key(): string
    {
        return 'agents.set_trust_level';
    }

    public function name(): string
    {
        return 'Propor nível de confiança';
    }

    public function description(): string
    {
        return 'Propõe subir ou descer o nível de confiança (N0 a N4) de um agente, com base no histórico dele (aprovações aceites e devolvidas, erros). Uma pessoa confirma.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->description('Chave do agente.')->required(),
            'level' => $schema->integer()->min(0)->max(4)->required(),
            'reason' => $schema->string()->description('O histórico que justifica, em poucas linhas.')->required(),
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function ceilingReason(array $arguments, CapabilityContext $context): string
    {
        return 'mudar a confiança de um agente é confirmado por uma pessoa';
    }

    public function summarise(array $arguments): string
    {
        $agent = Agent::query()->where('key', (string) ($arguments['agent'] ?? ''))->first();
        $to = AutonomyLevel::tryFrom((int) ($arguments['level'] ?? -1));

        return sprintf('Mudar a confiança de %s de %s para %s. %s', $agent->name ?? ($arguments['agent'] ?? '?'), $agent?->autonomy_level->code() ?? '?', $to?->code() ?? '?', $arguments['reason'] ?? '');
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'agent' => 'required|string|max:120',
            'level' => 'required|integer|min:0|max:4',
            'reason' => 'required|string|max:2000',
        ])->validate();

        $agent = Agent::query()->where('key', $data['agent'])->first();

        if ($agent === null) {
            return CapabilityResult::error("não há nenhum agente «{$data['agent']}».");
        }

        $from = $agent->autonomy_level;
        $agent->forceFill(['autonomy_level' => AutonomyLevel::from($data['level'])])->save();

        AuditLog::record($context->agent, 'agent.trust_changed', [
            'agent_id' => $agent->id,
            'from' => $from->value,
            'to' => $data['level'],
            'reason' => $data['reason'],
            'approval_id' => $context->approval?->id,
        ], subject: $agent);

        return CapabilityResult::text("{$agent->name} passou de {$from->code()} para {$agent->autonomy_level->code()}.");
    }
}
