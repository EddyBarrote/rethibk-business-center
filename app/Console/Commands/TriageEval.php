<?php

namespace App\Console\Commands;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Eval\TriageJudge;
use App\Ai\Runs\AgentDirectory;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Measures the triage (docs/DECISOES.md, "Avaliação da triagem", an idea from
 * OpenDuet): the labelled emails in database/eval/triage.json go to the
 * triage agent's model with its own instructions, and the answers are
 * compared with the labels. Run it before and after changing the triage's
 * instructions or model, to know whether it got worse. Costs a few cents.
 */
#[Signature('triage:eval {tenant : O slug da empresa} {--limit= : Só os primeiros N emails} {--min=0.8 : Acerto mínimo na categoria}')]
#[Description('Mede a triagem contra o conjunto de emails etiquetados')]
class TriageEval extends Command
{
    public function handle(TenantManager $tenants, AgentDirectory $agents, BudgetGuard $budget): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->components->error('Não há empresa com esse slug.');

            return self::FAILURE;
        }

        $cases = json_decode((string) file_get_contents(database_path('eval/triage.json')), true);
        $cases = array_slice((array) $cases, 0, $this->option('limit') !== null ? (int) $this->option('limit') : null);

        return $tenants->run($tenant, function () use ($cases, $agents, $budget): int {
            $triage = $agents->forRole('triage');

            if ($triage === null) {
                $this->components->error('A empresa não tem Agente de Triagem activo.');

                return self::FAILURE;
            }

            $provider = $triage->provider ?: (string) config('ai.default');
            $model = $triage->model ?: (config('agents.model') ?: null);
            $judge = new TriageJudge((string) $triage->instructions);
            $hits = ['category' => 0, 'priority' => 0];
            $rows = [];

            foreach ($cases as $case) {
                try {
                    $response = $judge->prompt("De: {$case['from']}\nAssunto: {$case['subject']}\n\n<email_externo_nao_confiavel>\n{$case['body']}\n</email_externo_nao_confiavel>", provider: $provider, model: $model, timeout: 60);
                    $answer = $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);
                    $budget->charge('triage.eval', $response->meta->provider ?? $provider, $response->meta->model ?? $model, (int) $response->usage->inputTokens, (int) $response->usage->outputTokens, agent: $triage);
                } catch (Throwable $e) {
                    $this->components->error('O modelo falhou: '.$e->getMessage());

                    return self::FAILURE;
                }

                $category = (string) ($answer['category'] ?? '');
                $priority = (string) ($answer['priority'] ?? '');
                $hits['category'] += (int) ($category === $case['category']);
                $hits['priority'] += (int) ($priority === $case['priority']);

                if ($category !== $case['category'] || $priority !== $case['priority']) {
                    $rows[] = [$case['subject'], "{$case['category']} / {$case['priority']}", "{$category} / {$priority}"];
                }
            }

            $total = max(1, count($cases));
            $categoryScore = $hits['category'] / $total;

            if ($rows !== []) {
                $this->table(['Email', 'Esperado', 'A triagem disse'], $rows);
            }

            $this->components->twoColumnDetail('Categoria certa', sprintf('%d de %d (%d%%)', $hits['category'], count($cases), round($categoryScore * 100)));
            $this->components->twoColumnDetail('Prioridade certa', sprintf('%d de %d (%d%%)', $hits['priority'], count($cases), round($hits['priority'] / $total * 100)));

            return $categoryScore >= (float) $this->option('min') ? self::SUCCESS : self::FAILURE;
        });
    }
}
