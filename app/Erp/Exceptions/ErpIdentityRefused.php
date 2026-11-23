<?php

namespace App\Erp\Exceptions;

use App\Models\Agent;
use Throwable;

/**
 * The ERP refused a call made with an agent's own identity (its token): the
 * tool is not ticked on the token, or the token is no longer valid. The
 * gateway never retries with the tenant's token, which would defeat the
 * identity (docs/DECISOES.md, "Identidade própria de cada agente no ERP").
 *
 * The message is what the agent reads; reason() is the short version for the
 * audit and for the admins on the agent's settings.
 */
final class ErpIdentityRefused extends ErpException
{
    private function __construct(string $message, private readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function toolNotAllowed(Agent $agent, string $tool, ?Throwable $previous = null): self
    {
        $reason = "A identidade própria de {$agent->name} no ERP não tem permissão para usar «{$tool}».";

        return new self(
            $reason.' Um administrador do ERP pode marcar esta ferramenta no token deste agente (Setup › MCP Server), ou tirar-lhe esta capacidade na plataforma. '
            .'Não tentes de novo nem de outra forma; diz na resposta que a tua identidade no ERP não tem esta permissão.',
            $reason,
            $previous,
        );
    }

    public static function tokenRejected(Agent $agent, ?Throwable $previous = null): self
    {
        $reason = "O ERP recusou a identidade própria de {$agent->name}: o token deste agente é inválido, expirou ou foi revogado.";

        return new self(
            $reason.' Um administrador tem de o substituir na configuração do agente (Identidade no ERP). '
            .'Não tentes de novo nem de outra forma; diz na resposta que a tua identidade no ERP foi recusada.',
            $reason,
            $previous,
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
