# MICOMOC — Plataforma de Agentes (Rethink)

Plataforma multi-tenant de gestão empresarial com agentes de IA, construída em Laravel 13 + `laravel/ai` + `laravel/mcp`.
MICOMOC é o primeiro tenant. Ref: RT-2026-MCM-01.

- Especificação de execução (fonte de verdade): [`docs/SPEC.md`](docs/SPEC.md)
- Estado das entregas: [`docs/E00-FUNDACAO.md`](docs/E00-FUNDACAO.md), [`docs/E01-MCP.md`](docs/E01-MCP.md)
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
php artisan migrate --seed      # cria o tenant "micomoc" com dados de desenvolvimento
npm install
composer dev                    # servidor, Horizon, Reverb, logs e Vite
```

Abrir <http://micomoc.localhost:8000> e entrar com `owner@micomoc.test` / `password` (só existe no seed de desenvolvimento).

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
