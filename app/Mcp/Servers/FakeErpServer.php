<?php

namespace App\Mcp\Servers;

use App\Mcp\FakeErp\FakeErpTool;
use App\Mcp\FakeErp\Modules\Core;
use App\Mcp\FakeErp\Modules\Crm;
use App\Mcp\FakeErp\Modules\Expenses;
use App\Mcp\FakeErp\Modules\Invoices;
use App\Mcp\FakeErp\Modules\Leads;
use App\Mcp\FakeErp\Modules\Procurement;
use App\Mcp\FakeErp\Modules\Projects;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Local stand-in for the Rethink ERP's MCP server (section 8.2), exposing the
 * section 8.3 surface over fixture data. Started over stdio with
 * `php artisan mcp:start fake-erp`.
 */
#[Name('Rethink ERP (fake)')]
#[Version('0.1.0')]
#[Instructions('Servidor falso do Rethink ERP para desenvolvimento. Dados fictícios em MZN. As ferramentas que escrevem exigem idempotency_key e só criam rascunhos.')]
class FakeErpServer extends Server
{
    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected function boot(): void
    {
        $this->tools = self::catalogue();
    }

    /**
     * @return list<FakeErpTool>
     */
    public static function catalogue(): array
    {
        $modules = [new Core, new Crm, new Leads, new Projects, new Invoices, new Procurement, new Expenses];

        return array_merge(...array_map(fn ($module) => $module->tools(), $modules));
    }

    public static function find(string $name): FakeErpTool
    {
        foreach (self::catalogue() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        throw new \InvalidArgumentException("Unknown fake ERP tool [{$name}].");
    }
}
