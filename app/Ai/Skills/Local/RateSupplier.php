<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\SupplierRating;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

final class RateSupplier extends LocalSkill
{
    public function key(): string
    {
        return 'suppliers.rate';
    }

    public function name(): string
    {
        return 'Avaliar fornecedor';
    }

    public function description(): string
    {
        return 'Regista a avaliação de um fornecedor depois de uma entrega: pontualidade, qualidade e preço, de 1 a 5, com nota.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_id' => $schema->string()->description('Id no ERP, ex.: SUP-0001.')->required(),
            'supplier_name' => $schema->string()->required(),
            'po_ref' => $schema->string(),
            'on_time' => $schema->integer()->min(1)->max(5)->required(),
            'quality' => $schema->integer()->min(1)->max(5)->required(),
            'price' => $schema->integer()->min(1)->max(5)->required(),
            'notes' => $schema->string(),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'supplier_id' => 'required|string|max:255',
            'supplier_name' => 'required|string|max:255',
            'po_ref' => 'nullable|string|max:255',
            'on_time' => 'required|integer|between:1,5',
            'quality' => 'required|integer|between:1,5',
            'price' => 'required|integer|between:1,5',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        $rating = SupplierRating::query()->create([
            'supplier_ref' => $data['supplier_id'],
            'supplier_name' => $data['supplier_name'],
            'po_ref' => $data['po_ref'] ?? null,
            'on_time' => $data['on_time'],
            'quality' => $data['quality'],
            'price' => $data['price'],
            'notes' => $data['notes'] ?? null,
            'rated_by_type' => 'agent',
            'rated_by_id' => $context->agent->id,
        ]);

        return SkillResult::data(['rating_id' => $rating->id]);
    }
}
