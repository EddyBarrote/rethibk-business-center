<?php

namespace App\Ai\Agents;

use App\Ai\Budget\BudgetExceeded;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\MissingProviderKey;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * The "describe the colleague you need" assistant (docs/CAPACIDADES.md):
 * turns a description into a draft for the agent form, with capability and
 * skill ids already resolved. Nothing is saved here.
 */
final class AgentDrafting
{
    public function __construct(private readonly BudgetGuard $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function draft(string $description): array
    {
        $this->assertCanCall();

        $capabilities = Capability::query()->usable()->whereNotIn('key', CapabilityRegistry::hidden())->orderBy('key')->get();
        $skills = Skill::query()->with('platformSkill')->get()->filter(fn (Skill $skill) => $skill->isUsable())->values();

        $response = (new AgentDrafter($this->catalogue($capabilities, $skills)))
            ->prompt("O colega de que preciso:\n".$description, provider: config('ai.default'), model: config('agents.model') ?: null);

        $draft = $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);
        $key = Str::slug((string) ($draft['key'] ?? $draft['name'] ?? 'agente'));

        return [
            'name' => Str::limit((string) ($draft['name'] ?? ''), 255, ''),
            'key' => $this->uniqueKey($key !== '' ? $key : 'agente'),
            'title' => Str::limit((string) ($draft['title'] ?? ''), 255, ''),
            'description' => Str::limit((string) ($draft['description'] ?? ''), 2000, ''),
            'personality' => Str::limit((string) ($draft['personality'] ?? ''), 5000, ''),
            'instructions' => Str::limit((string) ($draft['instructions'] ?? ''), 20000, ''),
            'autonomy_level' => (AutonomyLevel::tryFrom((int) ($draft['autonomy_level'] ?? 1)) ?? AutonomyLevel::Suggest)->value,
            'status' => AgentStatus::Draft->value,
            'capabilities' => $capabilities->whereIn('key', (array) ($draft['capabilities'] ?? []))->pluck('id')->values()->all(),
            'skills' => $skills->whereIn('key', (array) ($draft['skills'] ?? []))->pluck('id')->values()->all(),
            'suggested_skills' => collect((array) ($draft['suggested_skills'] ?? []))
                ->filter(fn ($skill) => is_array($skill) && filled($skill['name'] ?? null))
                ->take(3)
                ->map(fn (array $skill) => ['name' => (string) $skill['name'], 'description' => (string) ($skill['description'] ?? '')])
                ->values()
                ->all(),
            'brief' => $description,
        ];
    }

    /**
     * The default text provider has a key (or tests fake the drafter).
     */
    public static function available(): bool
    {
        $config = config('ai.providers.'.config('ai.default'));

        return AgentDrafter::isFaked() || ! is_array($config) || ! array_key_exists('key', $config) || filled($config['key']);
    }

    /**
     * Same checks as an agent run: a key must exist and the tenant's monthly
     * AI budget must not be spent.
     */
    private function assertCanCall(): void
    {
        $provider = (string) config('ai.default');

        if (! self::available()) {
            throw new MissingProviderKey("Falta a chave da API do provedor {$provider} no ficheiro .env (por exemplo GEMINI_API_KEY). Depois de a pôr, reinicie o composer dev.");
        }

        if ($this->budget->budget()->tenantMonthly !== null && $this->budget->tenantSpent() >= $this->budget->tenantCap()) {
            throw new BudgetExceeded('tenant', 'O orçamento mensal de IA da organização está esgotado.');
        }
    }

    /**
     * @param  Collection<int, Capability>  $capabilities
     * @param  Collection<int, Skill>  $skills
     */
    private function catalogue(Collection $capabilities, Collection $skills): string
    {
        $lines = ['Capacidades (ferramentas):'];

        foreach ($capabilities as $capability) {
            $lines[] = "- {$capability->key}: {$capability->name}".($capability->description ? ' — '.Str::limit($capability->description, 160) : '').($capability->is_mutating ? ' [escreve]' : '');
        }

        $lines[] = '';
        $lines[] = 'Skills (instruções):';

        foreach ($skills as $skill) {
            $lines[] = "- {$skill->key}: {$skill->displayName()} — ".Str::limit($skill->displayDescription(), 200);
        }

        if ($skills->isEmpty()) {
            $lines[] = '(nenhuma ainda)';
        }

        return implode("\n", $lines);
    }

    private function uniqueKey(string $key): string
    {
        $candidate = Str::limit($key, 60, '');
        $n = 2;

        while (Agent::query()->where('key', $candidate)->exists()) {
            $candidate = Str::limit($key, 56, '').'-'.$n++;
        }

        return $candidate;
    }
}
