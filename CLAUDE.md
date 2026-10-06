# MICOMOC — notas para agentes de código

- A especificação é `docs/SPEC.md`. Tudo marcado `[CONFIRMAR]` não está decidido: perguntar, nunca inventar.
- Implementar por entregas (secção 19), pela ordem. Estado em `docs/E00-FUNDACAO.md`, `docs/E01-MCP.md` e `docs/E02-AGENTES.md`; decisões em `docs/DECISOES.md`.
- Toda a tabela de domínio tem `tenant_id` e o modelo usa `App\Concerns\BelongsToTenant`. O teste
  `tests/Feature/Tenancy/IsolationTest.php` descobre os modelos sozinho: um modelo novo precisa só do trait e de uma factory.
- Nunca usar `withoutGlobalScope(s)` em `app/` (há um teste de arquitectura que o impede).
- Regras de validação que tocam a base de dados (`exists`, `unique`) usam `App\Tenancy\TenantRule`, porque saltam os scopes do Eloquent.
- Jobs com dados de tenant estendem `App\Tenancy\TenantAwareJob` e levam `tenantId` no payload.
- O ERP só se chama através de `App\Erp\ErpGateway`, que audita cada chamada; os conectores (MCP/HTTP de admins) só através de `App\Connectors\ConnectorGateway`. `Laravel\Mcp\Client` fora destes dois falha num teste de arquitectura.
- `audit_logs` é append-only: registar com `AuditLog::record()`, nunca alterar nem apagar.
- Antes de concluir: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npx tsc --noEmit`.
- Interface em português (pt-MZ/pt-PT).
- Agentes são genéricos (uma linha em `agents`, criada pelo super admin ou pelos admins da empresa); não criar classes de agente por função.
- **Capacidade** = ferramenta executável (`App\Models\Capability`): local em `App\Ai\Capabilities\Local` (registar em `CapabilityRegistry` ou com `CapabilityRegistry::register()` num service provider), do ERP, ou de um conector MCP/HTTP. **Skill** = pacote de instruções ao estilo do Claude (`App\Models\Skill`). Ver `docs/CAPACIDADES.md`.
- Eventos de consola ao vivo usam `::live(...)` (trait `BroadcastsLive`), nunca `::dispatch`, para uma falha do Reverb não partir execuções.
- Os seis agentes da proposta são modelos em `App\Ai\Templates\AgentTemplates`; o papel de um agente (`settings.template`) encontra-se com `AgentDirectory::forRole()`.
- Limiares de negócio lêem-se com `App\Support\TenantSettings::int()` (tenant, depois `config/business.php`), nunca valores fixos.
- Testes de agentes: `GenericAgent::fake([...ToolCall..., 'texto'])` com os nomes das ferramentas com `_` em vez de `.`; `templateAgent()` e `runCapability()` em `tests/Pest.php`.
