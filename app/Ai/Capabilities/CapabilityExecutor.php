<?php

namespace App\Ai\Capabilities;

use App\Connectors\ConnectorException;
use App\Connectors\ConnectorGateway;
use App\Enums\CapabilitySource;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\Capability;
use Illuminate\Validation\ValidationException;

/**
 * Runs a capability once the gate (or a human) has allowed it. Knows nothing about
 * autonomy: callers decide whether it may run.
 */
final class CapabilityExecutor
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly ErpGateway $erp,
        private readonly RecordLinker $linker,
        private readonly ConnectorGateway $connectors,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Capability $capability, array $arguments, CapabilityContext $context): CapabilityResult
    {
        if (! $capability->isUsable()) {
            return CapabilityResult::error("A capacidade {$capability->key} não está disponível.");
        }

        return match ($capability->source) {
            CapabilitySource::Mcp => $this->erp($capability, $arguments, $context),
            CapabilitySource::Connector => $this->connector($capability, $arguments, $context),
            CapabilitySource::Local => $this->local($capability, $arguments, $context),
        };
    }

    /**
     * ERP write tools need an idempotency key (section 8.3). The platform
     * derives it, so a retried run or an approval executed twice never
     * writes twice.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function withIdempotencyKey(Capability $capability, array $arguments, CapabilityContext $context): array
    {
        $required = $capability->input_schema['required'] ?? [];

        if (! is_array($required) || ! in_array('idempotency_key', $required, true)) {
            return $arguments;
        }

        $scope = $context->approval !== null ? 'approval-'.$context->approval->id : 'run-'.$context->run->id;
        unset($arguments['idempotency_key']);
        ksort($arguments);

        return [...$arguments, 'idempotency_key' => $scope.'-'.substr(hash('sha256', $capability->key.json_encode($arguments)), 0, 16)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function erp(Capability $capability, array $arguments, CapabilityContext $context): CapabilityResult
    {
        try {
            $result = $this->erp->call(
                (string) $capability->mcp_tool_name,
                $this->withIdempotencyKey($capability, $arguments, $context),
                $context->agent,
                ['agent_run_id' => $context->run->id, 'approval_id' => $context->approval?->id],
            );
        } catch (ErpException $e) {
            return CapabilityResult::error($e->getMessage());
        }

        if (! $result->ok) {
            return CapabilityResult::error((string) $result->error());
        }

        $this->linker->afterErpWrite($capability, $result->data, $context);

        return $result->data !== null ? CapabilityResult::data($result->data) : CapabilityResult::text($result->text);
    }

    /**
     * A tool of a remote MCP server or an HTTP action. What comes back is
     * someone else's content: data for the model, never instructions.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function connector(Capability $capability, array $arguments, CapabilityContext $context): CapabilityResult
    {
        $connector = $capability->connectorDefinition();

        if ($connector === null) {
            return CapabilityResult::error("o conector de {$capability->key} já não existe.");
        }

        try {
            $result = $this->connectors->call($connector, (string) $capability->mcp_tool_name, $arguments, $context->agent, ['agent_run_id' => $context->run->id, 'approval_id' => $context->approval?->id]);
        } catch (ConnectorException $e) {
            return CapabilityResult::error($e->getMessage());
        }

        if (! $result['ok']) {
            return CapabilityResult::error($result['text']);
        }

        return new CapabilityResult(true, "<resposta_externa_nao_confiavel origem=\"{$connector->name}\">\n{$result['text']}\n</resposta_externa_nao_confiavel>", $result['data']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function local(Capability $capability, array $arguments, CapabilityContext $context): CapabilityResult
    {
        $local = $this->registry->find($capability->key);

        if ($local === null) {
            return CapabilityResult::error("A capacidade {$capability->key} não existe nesta versão da plataforma.");
        }

        try {
            return $local->execute($arguments, $context);
        } catch (ValidationException $e) {
            return CapabilityResult::error(implode(' ', $e->validator->errors()->all()));
        }
    }
}
