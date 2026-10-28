<?php

namespace App\Ai;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The models each provider offers today, asked to the provider itself with the
 * company's key (docs/DECISOES.md, "Modelos de cada provedor"), so a new one
 * (Gemini 3.8, …) can be chosen without waiting for the catalogue. Kept for a
 * day per company and provider; when the provider does not answer, only the
 * catalogue (agents.models) is offered.
 */
final class ProviderModels
{
    private const OPENAI_COMPATIBLE = [
        'openai' => 'https://api.openai.com/v1/models',
        'openrouter' => 'https://openrouter.ai/api/v1/models',
        'deepseek' => 'https://api.deepseek.com/models',
        'xai' => 'https://api.x.ai/v1/models',
        'mistral' => 'https://api.mistral.ai/v1/models',
    ];

    /** Not for writing text: images, voice, embeddings, moderation and the like. */
    private const NOT_TEXT = '/(embed|image|imagen|tts|audio|speech|transcri|whisper|live|moderation|dall-e|veo|lyria|robotics|computer-use|search|realtime|aqa|vision-preview|ocr)/i';

    /**
     * @return list<array{model: string, label: string}>
     */
    public function for(string $provider): array
    {
        $key = (string) config("ai.providers.{$provider}.key");

        if (! config('agents.discover_models', true) || $key === '') {
            return [];
        }

        $cacheKey = 'provider-models:'.(Tenant::current()->id ?? 0).":{$provider}:".substr(hash('sha256', $key), 0, 12);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // A failure is not kept: the next page asks again.
        $found = (function () use ($provider, $key): ?array {
            try {
                $models = match (true) {
                    $provider === 'gemini' => $this->gemini($key),
                    $provider === 'anthropic' => $this->anthropic($key),
                    isset(self::OPENAI_COMPATIBLE[$provider]) => $this->openAiCompatible(self::OPENAI_COMPATIBLE[$provider], $key),
                    default => [],
                };
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            return collect($models)
                ->reject(fn (array $m) => preg_match(self::NOT_TEXT, $m['model']) === 1)
                ->unique('model')->sortBy('label')->values()->all();
        })();

        if ($found === null) {
            return [];
        }

        Cache::put($cacheKey, $found, now()->addDay());

        return $found;
    }

    /** @return list<array{model: string, label: string}> */
    private function gemini(string $key): array
    {
        $response = Http::timeout(6)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key, 'pageSize' => 1000])->throw();

        return collect((array) $response->json('models'))
            ->filter(fn (array $m) => in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true))
            ->map(fn (array $m) => ['model' => Str::after((string) $m['name'], 'models/'), 'label' => (string) ($m['displayName'] ?? Str::after((string) $m['name'], 'models/'))])
            ->filter(fn (array $m) => str_starts_with($m['model'], 'gemini-'))
            ->values()->all();
    }

    /** @return list<array{model: string, label: string}> */
    private function anthropic(string $key): array
    {
        $response = Http::timeout(6)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->get('https://api.anthropic.com/v1/models', ['limit' => 100])->throw();

        return collect((array) $response->json('data'))
            ->map(fn (array $m) => ['model' => (string) $m['id'], 'label' => (string) ($m['display_name'] ?? $m['id'])])
            ->values()->all();
    }

    /** @return list<array{model: string, label: string}> */
    private function openAiCompatible(string $url, string $key): array
    {
        $response = Http::timeout(6)->withToken($key)->get($url)->throw();

        return collect((array) $response->json('data'))
            ->map(fn (array $m) => ['model' => (string) $m['id'], 'label' => (string) ($m['name'] ?? $m['id'])])
            ->values()->all();
    }
}
