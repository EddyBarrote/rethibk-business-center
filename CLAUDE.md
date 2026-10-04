# MICOMOC — notas para agentes de código

- A especificação é `docs/SPEC.md`. Tudo marcado `[CONFIRMAR]` não está decidido: perguntar, nunca inventar.
- Implementar por entregas (secção 19), pela ordem. Estado em `docs/E00-FUNDACAO.md` e `docs/E01-MCP.md`; decisões em `docs/DECISOES.md`.
- Toda a tabela de domínio tem `tenant_id` e o modelo usa `App\Concerns\BelongsToTenant`. O teste
  `tests/Feature/Tenancy/IsolationTest.php` descobre os modelos sozinho: um modelo novo precisa só do trait e de uma factory.
- Nunca usar `withoutGlobalScope(s)` em `app/` (há um teste de arquitectura que o impede).
- Regras de validação que tocam a base de dados (`exists`, `unique`) usam `App\Tenancy\TenantRule`, porque saltam os scopes do Eloquent.
- Jobs com dados de tenant estendem `App\Tenancy\TenantAwareJob` e levam `tenantId` no payload.
- O ERP só se chama através de `App\Erp\ErpGateway`, que audita cada chamada; `Laravel\Mcp\Client` fora de `App\Erp` falha num teste de arquitectura.
- `audit_logs` é append-only: registar com `AuditLog::record()`, nunca alterar nem apagar.
- Antes de concluir: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npx tsc --noEmit`.
- Interface em português (pt-MZ/pt-PT).
