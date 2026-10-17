<?php

use App\Ai\Agents\AgentEditor;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Web\WebSearcher;
use App\Models\Agent;
use App\Models\AiCharge;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\TextResponse;

function webAnswer(string $text, array $citations = []): TextResponse
{
    return new TextResponse($text, new TextUsage(300, 1200), new Meta('gemini', 'gemini-3.6-flash', collect($citations)));
}

beforeEach(function () {
    config(['agents.web_search.fee_usd' => 0.014]);
});

it('searches the internet and returns the answer with the real addresses of its sources', function () {
    Http::fake([
        'vertexaisearch.cloud.google.com/grounding-api-redirect/abc' => Http::response('', 302, ['Location' => 'https://sovagas.test/concurso-gestao-documental']),
        'vertexaisearch.cloud.google.com/grounding-api-redirect/def' => Http::response('', 302, ['Location' => 'https://sovagas.test/concurso-gestao-documental']),
        'vertexaisearch.cloud.google.com/grounding-api-redirect/ghi' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
    ]);

    WebSearcher::fake([webAnswer('1. Associação Ungagodoli: concurso para um website, prazo 30/10 às 11:00.', [
        new UrlCitation('https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc', 'sovagas.co.mz'),
        new UrlCitation('https://vertexaisearch.cloud.google.com/grounding-api-redirect/def', 'sovagas.co.mz'),
        new UrlCitation('https://vertexaisearch.cloud.google.com/grounding-api-redirect/ghi', 'interno'),
    ])]);

    asTenant(Tenant::factory()->create(), function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();

        $result = runCapability($agent, 'web.search', ['query' => 'concursos abertos em Moçambique para websites']);

        expect($result->ok)->toBeTrue()
            ->and($result->content)
            ->toContain('<resultados_web_nao_confiaveis')
            ->toContain('Associação Ungagodoli')
            ->toContain('1. sovagas.co.mz: https://sovagas.test/concurso-gestao-documental')
            ->toContain('2. interno: https://vertexaisearch.cloud.google.com/grounding-api-redirect/ghi')
            ->not->toContain('127.0.0.1')
            ->toContain('diz que é da internet');

        $charge = AiCharge::query()->sole();
        expect($charge->purpose)->toBe('web.search')
            ->and($charge->agent_id)->toBe($agent->id)
            ->and((float) $charge->cost_usd)->toBe(round((300 * 1.50 + 1200 * 7.50) / 1_000_000 + 0.014, 6));

        expect(AuditLog::query()->where('action', 'web.search')->sole()->payload['query'])->toBe('concursos abertos em Moçambique para websites');
    });
});

it('says so instead of searching when no provider key is set', function () {
    config(['agents.web_search.provider' => 'gemini', 'ai.providers.gemini.key' => null]);

    asTenant(Tenant::factory()->create(), function () {
        app(CapabilityCatalog::class)->syncLocal();

        $result = runCapability(Agent::factory()->create(), 'web.search', ['query' => 'concursos']);

        expect($result->ok)->toBeFalse()
            ->and($result->content)->toContain('não está configurada');
        expect(AiCharge::query()->count())->toBe(0);
    });
});

it('switches searching and reading the internet on for every agent once, and an admin can turn them off', function () {
    asTenant(Tenant::factory()->create(), function () {
        $catalog = app(CapabilityCatalog::class);
        $first = Agent::factory()->create();
        $second = Agent::factory()->create();

        $catalog->syncLocal();

        foreach ([$first, $second] as $agent) {
            expect($agent->capabilities()->wherePivot('enabled', true)->pluck('key')->all())->toContain('web.search', 'web.read_page');
        }

        $first->capabilities()->detach(Capability::query()->where('key', 'web.search')->value('id'));
        $catalog->syncLocal();

        expect($first->capabilities()->pluck('key')->all())->not->toContain('web.search')->toContain('web.read_page');
    });
});

it('tells the agent when to look on the internet only when it can', function () {
    asTenant(Tenant::factory()->create(), function () {
        $agent = Agent::factory()->create();
        app(CapabilityCatalog::class)->syncLocal();

        expect(app(InstructionComposer::class)->for($agent))->toContain('## Internet')->toContain('web.search');

        $agent->capabilities()->updateExistingPivot(Capability::query()->where('key', 'web.search')->value('id'), ['enabled' => false]);

        expect(app(InstructionComposer::class)->for($agent->fresh()))->not->toContain('## Internet');
    });
});

it('ticks the internet on a new agent and gives it to agents installed from a template', function () {
    $store = freshFakeErp();

    asTenant(Tenant::factory()->create(), function () {
        $agent = templateAgent('client_manager');

        expect($agent->capabilities()->pluck('key')->all())->toContain('web.search', 'web.read_page');

        $defaults = app(AgentEditor::class)->options()['defaultCapabilities'];
        expect(Capability::query()->whereKey($defaults)->pluck('key')->sort()->values()->all())->toBe(['code.run', 'email.draft', 'web.read_page', 'web.search']);
    });

    @unlink($store);
});
