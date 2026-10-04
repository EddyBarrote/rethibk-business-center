<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\SupplierRating;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class SupplierScores extends LocalSkill
{
    public function key(): string
    {
        return 'suppliers.scores';
    }

    public function name(): string
    {
        return 'Pontuação dos fornecedores';
    }

    public function description(): string
    {
        return 'Média das avaliações por fornecedor (pontualidade, qualidade, preço) e número de avaliações.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['supplier_id' => $schema->string()];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        return SkillResult::data(['suppliers' => self::scores(isset($arguments['supplier_id']) ? (string) $arguments['supplier_id'] : null)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function scores(?string $supplier = null): array
    {
        return SupplierRating::query()
            ->when($supplier, fn ($q) => $q->where('supplier_ref', $supplier))
            ->selectRaw('supplier_ref, max(supplier_name) as supplier_name, count(*) as ratings, avg(on_time) as on_time, avg(quality) as quality, avg(price) as price, max(created_at) as last_rated_at')
            ->groupBy('supplier_ref')
            ->get()
            ->map(fn (SupplierRating $row) => [
                'supplier_id' => $row->supplier_ref,
                'supplier_name' => $row->supplier_name,
                'ratings' => (int) $row->getAttribute('ratings'),
                'on_time' => round((float) $row->getAttribute('on_time'), 2),
                'quality' => round((float) $row->getAttribute('quality'), 2),
                'price' => round((float) $row->getAttribute('price'), 2),
                'overall' => round(((float) $row->getAttribute('on_time') + (float) $row->getAttribute('quality') + (float) $row->getAttribute('price')) / 3, 2),
                'last_rated_at' => $row->getAttribute('last_rated_at'),
            ])
            ->sortByDesc('overall')
            ->values()
            ->all();
    }
}
