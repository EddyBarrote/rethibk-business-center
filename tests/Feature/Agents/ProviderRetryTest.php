<?php

use Illuminate\Support\Facades\Http;

// A model provider that drops a request gets it again (docs/DECISOES.md, "Repetir quando o provedor falha").

beforeEach(fn () => config(['agents.provider_retry.delay_ms' => 0]));

it('sends a request to a model provider again when it answers 503 or 429', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push('', 503)->push('', 429)->push(['ok' => true])]);

    $response = Http::post('https://generativelanguage.googleapis.com/v1beta/interactions', ['x' => 1]);

    expect($response->json('ok'))->toBeTrue();
    Http::assertSentCount(3);
});

it('gives up after the configured tries', function () {
    config(['agents.provider_retry.times' => 2]);
    Http::fake(['api.anthropic.com/*' => Http::response('', 503)]);

    expect(Http::post('https://api.anthropic.com/v1/messages')->status())->toBe(503);
    Http::assertSentCount(3);
});

it('does not repeat other hosts or errors that an answer would not fix', function () {
    Http::fake(['example.com/*' => Http::response('', 503), 'api.openai.com/*' => Http::response('', 400)]);

    Http::get('https://example.com/x');
    Http::post('https://api.openai.com/v1/responses');

    Http::assertSentCount(2);
});
