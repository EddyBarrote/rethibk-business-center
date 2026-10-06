# E00 — Fundação

Estado da entrega E00 (secção 19 da [especificação](SPEC.md)).

## Versões verificadas (04.10.2026)

Resolvidas pelo Composer/npm, não apenas lidas na documentação:

| Pacote | Versão instalada | Nota |
|---|---|---|
| `laravel/framework` | 13.34.0 | `php artisan --version` confirma Laravel 13 |
| `laravel/ai` | 1.0.1 | exige PHP ^8.3 e `illuminate/*` ^12\|^13, como a especificação diz |
| `laravel/mcp` | 1.0.1 | `Mcp::registerClient`, `Client::web`, `Client::local`, `withToken`, `withTimeout` existem |
| `laravel/reverb` | 1.12.0 | |
| `laravel/horizon` | 5.50.0 | |
| `inertiajs/inertia-laravel` | 2.0.28 | a especificação pede Inertia 2; já existe a 3.x, não usada |
| `@inertiajs/react` | 2.3.28 | idem |
| `pestphp/pest` | 4.7.8 | a especificação pede ^4; já existe a 5.x, não usada |
| `larastan/larastan` | 3.12.2 | nível 6 |

`php artisan make:agent Probe` funcionou e gerou uma classe com `Promptable`, `Agent`, `Conversational` e `HasTools`
(o ficheiro de prova foi removido). `vendor:publish --tag=ai-routes` criou `routes/ai.php`; essa tag pertence ao `laravel/mcp`.
As restantes classes da secção 6.1 (`RemembersConversations`, `HasConversations`, `HasStructuredOutput`, `Tools\Request`,
`JsonSchema`, `SimilaritySearch::usingModel`) existem.

## Checklist

- [x] Repositório, Laravel 13, PHP 8.3, Pint, Larastan, Pest, CI
- [x] Tenancy completa (secção 4) + teste de isolamento a passar
- [x] Auth, utilizadores, departamentos, papéis
- [x] Layout Inertia + shadcn com o tema Rethink
- [ ] Horizon, Redis, Reverb a correr em local e em staging — **local verificado; staging depende da questão 21.6**

## O que foi construído

**Tenancy** (`app/Tenancy`, `app/Concerns/BelongsToTenant.php`)
- `TenantManager` (singleton) com `current()`, `set()`, `forget()`, `run()` e `eachActive()` para comandos agendados.
- `TenantScope` falha fechado: sem tenant definido, as consultas não devolvem nada.
- `BelongsToTenant` preenche `tenant_id` ao criar, recusa criar sem tenant e recusa mudar um registo de tenant.
- `IdentifyTenant` resolve por domínio próprio e depois por subdomínio do domínio central; 404 para host desconhecido, 403 para tenant suspenso.
- `EnsureUserBelongsToTenant` termina a sessão de um utilizador que apareça num host de outro tenant.
- `TenantAwareJob` + `SetTenantForJob`: o `tenantId` viaja no payload e o tenant é definido/esquecido à volta do `handle()`
  (por middleware de job, para que cada job mantenha o seu `handle()` com injecção de dependências, como o `RunAgent` da secção 6.4).
- `TenantRule::exists/unique` para validação, que de outro modo saltaria o scope.
- `php artisan tenant:create` para criar um tenant e o seu proprietário.

**Teste de isolamento** (`tests/Feature/Tenancy/IsolationTest.php`) descobre todos os modelos com o trait e verifica, para cada um:
via Eloquent (A não vê B, sem tenant não vê nada, criação carimbada, sem tenant falha, `tenant_id` imutável), e via HTTP
(listas, 404 para registos de outro tenant, validação rejeita referências cruzadas, sessão e credenciais não atravessam tenants).
Também falha se aparecer uma tabela com `tenant_id` sem modelo protegido. A parte "via ferramentas dos agentes" fica marcada
como `todo` até haver ferramentas (E02).

**Auth e utilizadores**: email e palavra-passe, com limite de tentativas e utilizadores inactivos bloqueados. Papéis
`owner/admin/manager/member`; proprietários e administradores gerem utilizadores e departamentos, só um proprietário cria
ou altera proprietários, ninguém muda o próprio papel nem se desactiva. Departamentos em árvore, sem ciclos.

**Frontend**: Inertia 2 + React 19 + TypeScript, componentes shadcn (new-york) em `resources/js/Components/ui`, tema com
`#0052CC` como único acento e Space Grotesk. Páginas: login, painel com estados vazios desenhados, utilizadores e departamentos.
Os módulos das entregas seguintes aparecem no menu, desactivados, com a entrega em que chegam.

**Filas e tempo real**: Horizon configurado com as filas e concorrências da secção 14.1; Reverb instalado, com os canais
`tenant.{id}.agents` e `tenant.{id}.user.{id}` autorizados por tenant (os de runs e aprovações chegam com as tabelas na E02).
Verificado em local: um job em `agents` processado pelo Horizon e um broadcast aceite pelo Reverb.

## Desvios da especificação, e porquê

- **`tailwind.config.js`**: o Laravel 13 traz Tailwind 4, que guarda os tokens em CSS. Os tokens da marca estão em `resources/css/app.css`.
- **shadcn**: o registo `ui.shadcn.com` não estava acessível no ambiente de construção, por isso os componentes foram escritos
  à mão a partir das fontes new-york. `components.json` está configurado para `npx shadcn@latest add …` funcionar normalmente.
  Instalados só os que a E00 usa; os restantes da secção 11.2 entram quando forem precisos.
- **Recuperação de palavra-passe**: fora da E00. A tabela de tokens do Laravel é por email global, o que não serve com
  emails únicos por tenant; entra com o provedor de email (questão 21.1).
- **Fonte**: Space Grotesk servida pelo próprio build (`@fontsource-variable`), sem pedidos a CDNs externos.

## Escolhas provisórias para questões em aberto (reversíveis)

Respostas já dadas pela direcção: ver [DECISOES.md](DECISOES.md).

| # | Questão | Provisório | Onde mudar |
|---|---|---|---|
| 6 | Alojamento e deploy | nenhum; só local e CI | — |
| 7 | Autenticação | email e palavra-passe | `LoginController` |
| 8 | Idioma da interface | só português (`APP_LOCALE=pt`) | textos nas páginas e `lang/pt` |
