<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Clients\ClientSheetBuilder;
use App\Erp\Exceptions\ErpException;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ClientSheet extends LocalSkill
{
    public function __construct(private readonly ClientSheetBuilder $sheets) {}

    public function key(): string
    {
        return 'clients.sheet';
    }

    public function name(): string
    {
        return 'Ficha de cliente';
    }

    public function description(): string
    {
        return 'Ficha viva de um cliente: dados e contactos do ERP, projectos, facturas em dívida, leads, contratos, emails recentes, pedidos sem resposta e seguimentos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['account_id' => $schema->string()->description('Id do cliente no ERP, ex.: ACC-0002 (crm.search_accounts).')->required()];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        try {
            return SkillResult::data($this->sheets->build((string) ($arguments['account_id'] ?? ''), $context->agent));
        } catch (ErpException $e) {
            return SkillResult::error($e->getMessage());
        }
    }
}
