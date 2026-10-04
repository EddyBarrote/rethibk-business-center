<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\SupplierRating;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The comparison map (section 7.3, CompareQuotes): ranks quotes on price,
 * delivery time and the supplier's rating history, with weights the agent
 * can change. The cheapest is not always the best.
 */
final class CompareQuotes extends LocalSkill
{
    public function key(): string
    {
        return 'analysis.compare_quotes';
    }

    public function name(): string
    {
        return 'Mapa comparativo de cotações';
    }

    public function description(): string
    {
        return 'Ordena cotações por pontuação ponderada de preço, prazo de entrega e avaliação histórica do fornecedor (pesos por omissão 60/25/15). Devolve a recomendada e porquê.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'quotes' => $schema->array()->min(1)->items($schema->object([
                'supplier_id' => $schema->string()->required(),
                'supplier_name' => $schema->string()->required(),
                'total' => $schema->number()->min(0)->required(),
                'delivery_days' => $schema->integer()->min(0),
                'quote_id' => $schema->string(),
                'notes' => $schema->string(),
            ]))->required(),
            'weights' => $schema->object([
                'price' => $schema->number(),
                'delivery' => $schema->number(),
                'rating' => $schema->number(),
            ]),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'quotes' => 'required|array|min:1|max:30',
            'quotes.*.supplier_id' => 'required|string',
            'quotes.*.supplier_name' => 'required|string',
            'quotes.*.total' => 'required|numeric|min:0',
            'quotes.*.delivery_days' => 'nullable|integer|min:0',
            'quotes.*.quote_id' => 'nullable|string',
            'quotes.*.notes' => 'nullable|string',
            'weights.price' => 'nullable|numeric|min:0',
            'weights.delivery' => 'nullable|numeric|min:0',
            'weights.rating' => 'nullable|numeric|min:0',
        ])->validate();

        return SkillResult::data(self::rank($data['quotes'], $data['weights'] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     * @param  array<string, mixed>  $weights
     * @return array<string, mixed>
     */
    public static function rank(array $quotes, array $weights = []): array
    {
        $w = ['price' => (float) ($weights['price'] ?? 60), 'delivery' => (float) ($weights['delivery'] ?? 25), 'rating' => (float) ($weights['rating'] ?? 15)];
        $sum = array_sum($w) ?: 1.0;
        $minPrice = min(array_map(fn ($q) => (float) $q['total'], $quotes));
        $days = array_filter(array_map(fn ($q) => $q['delivery_days'] ?? null, $quotes), fn ($d) => $d !== null);
        $minDays = $days === [] ? null : min($days);

        $rows = array_map(function (array $quote) use ($w, $sum, $minPrice, $minDays): array {
            $rating = SupplierRating::query()->where('supplier_ref', $quote['supplier_id'])->selectRaw('avg((on_time + quality + price) / 3.0) as score, count(*) as total')->first();
            $ratingScore = $rating !== null && (int) $rating->getAttribute('total') > 0 ? (float) $rating->getAttribute('score') : null;

            $priceScore = (float) $quote['total'] > 0 ? $minPrice / (float) $quote['total'] * 100 : 100;
            $deliveryScore = ($quote['delivery_days'] ?? null) === null || $minDays === null ? 50 : (($minDays ?: 1) / max(1, (int) $quote['delivery_days'])) * 100;
            $historyScore = $ratingScore === null ? 60 : $ratingScore / 5 * 100;

            return [
                ...$quote,
                'scores' => ['price' => round($priceScore, 1), 'delivery' => round($deliveryScore, 1), 'rating' => round($historyScore, 1)],
                'supplier_rating' => $ratingScore === null ? null : round($ratingScore, 2),
                'weighted' => round(($priceScore * $w['price'] + $deliveryScore * $w['delivery'] + $historyScore * $w['rating']) / $sum, 1),
                'above_cheapest_pct' => $minPrice > 0 ? round(((float) $quote['total'] - $minPrice) / $minPrice * 100, 1) : 0.0,
            ];
        }, $quotes);

        usort($rows, fn (array $a, array $b) => $b['weighted'] <=> $a['weighted']);
        $best = $rows[0];

        return [
            'weights' => $w,
            'ranking' => $rows,
            'recommended' => [
                'supplier_id' => $best['supplier_id'],
                'supplier_name' => $best['supplier_name'],
                'quote_id' => $best['quote_id'] ?? null,
                'why' => $best['above_cheapest_pct'] > 0
                    ? "melhor pontuação ponderada ({$best['weighted']}), {$best['above_cheapest_pct']}% acima da mais barata, compensado por prazo e histórico"
                    : "mais barata e melhor pontuação ponderada ({$best['weighted']})",
            ],
        ];
    }
}
