<?php

namespace App\Console\Commands\Agents;

use App\Ai\Agents\GenericAgent;
use App\Ai\Demo\DemoScenario;
use App\Ai\Runs\AgentRunner;
use App\Console\Concerns\InteractsWithTenant;
use App\Enums\TriggerType;
use App\Jobs\RunAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The E02 acceptance check (section 19). With an API key the run goes to the
 * queue and a real model decides; with --scripted (or no key) the model's
 * answers are scripted and the run happens in this process. Either way the
 * approval is then decided by a human in the console.
 */
#[Signature('agents:demo {tenant : Slug do tenant} {--scripted : Simula o modelo (não precisa de chave de API)}')]
#[Description('Prova da E02: um agente lê o ERP e pede aprovação para uma escrita acima do seu nível')]
class RunDemo extends Command
{
    use InteractsWithTenant;

    public function handle(DemoScenario $demo, AgentRunner $runner): int
    {
        return $this->asTenant(function () use ($demo, $runner): int {
            $agent = $demo->ensureAgent();
            $scripted = $this->option('scripted') || blank(config('ai.providers.'.($agent->provider ?: config('ai.default')).'.key'));

            $run = $runner->create($agent, DemoScenario::INPUT, TriggerType::Manual);

            if ($scripted) {
                $this->components->warn('Modelo simulado: sem chave de API, ou --scripted.');
                GenericAgent::fake(DemoScenario::scriptedResponses());
                $runner->run($run);
            } else {
                RunAgent::dispatch($run->tenant_id, $run->id)->onQueue((string) config('agents.queues.manual'));
            }

            $run->refresh();
            $this->components->twoColumnDetail('Execução', "#{$run->id} ({$run->status->label()})");
            $this->components->twoColumnDetail('Aprovações pendentes', (string) $run->approvals()->pending()->count());
            $this->components->info('Abre /runs/'.$run->id.' e /approvals na consola do tenant para acompanhar e aprovar.');

            return self::SUCCESS;
        });
    }
}
