# MICOMOC — Plataforma de Agentes (Rethink)

Plataforma multi-tenant de gestão empresarial com agentes de IA, construída em Laravel 13 + `laravel/ai` + `laravel/mcp`.
MICOMOC é o primeiro tenant. Ref: RT-2026-MCM-01.

- Especificação de execução (fonte de verdade): [`docs/SPEC.md`](docs/SPEC.md)
- Estado das entregas: [`E00`](docs/E00-FUNDACAO.md), [`E01`](docs/E01-MCP.md), [`E02`](docs/E02-AGENTES.md), [`E03`](docs/E03-TRIAGEM.md), [`E04 a E08`](docs/E04-E08-AREAS.md)
- **Como testar:** [`docs/TESTAR.md`](docs/TESTAR.md)
- Decisões tomadas: [`docs/DECISOES.md`](docs/DECISOES.md)
- Contrato para a equipa do ERP: [`docs/ERP-MCP-CONTRACT.md`](docs/ERP-MCP-CONTRACT.md)

## Stack

PHP 8.3 · Laravel 13 · `laravel/ai` 1.0 · `laravel/mcp` 1.0 · MySQL 8.4/9.x · Redis + Horizon · Reverb · Inertia 2 + React 19 + shadcn/ui + Tailwind 4 · Pest 4 · Pint · Larastan (nível 6).

## Arrancar em local

Requer PHP 8.3+, Composer, Node 22+, MySQL e Redis.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan reverb:install      # gera as chaves REVERB_* no .env
# criar a base de dados micomoc_agents no MySQL
php artisan migrate --seed      # tenant "micomoc", pessoas, os seis agentes e contratos de exemplo
npm install
composer dev                    # servidor, Horizon, Reverb, logs e Vite
```

Abrir <http://micomoc.localhost:8000> e entrar com `owner@micomoc.test` / `password` (só existe no seed de desenvolvimento). A consola do super admin está em <http://admin.localhost:8000> (`admin@rethink.test` / `password`). As outras contas e o percurso de teste estão em [`docs/TESTAR.md`](docs/TESTAR.md).

O seed liga o tenant ao servidor ERP falso. Para confirmar a camada MCP: `php artisan erp:smoke micomoc`.

Cada tenant é servido em `{slug}.TENANCY_CENTRAL_DOMAIN` (em local, `*.localhost` resolve sem alterar DNS) ou no domínio próprio definido em `tenants.domain`.

Para criar um tenant real:

```bash
php artisan tenant:create "MICOMOC" micomoc --owner-name="Nome" --owner-email=pessoa@exemplo.co.mz
```

## Verificações

```bash
php artisan test               # inclui tests/Feature/Tenancy/IsolationTest.php
vendor/bin/pint --test
vendor/bin/phpstan analyse
npx tsc --noEmit && npm run build
```

O CI (`.github/workflows/ci.yml`) corre o mesmo contra MySQL 8.4 e Redis, e verifica que as migrações são reversíveis.
