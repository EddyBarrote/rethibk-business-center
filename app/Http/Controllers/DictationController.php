<?php

namespace App\Http\Controllers;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Dictation\Dictation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Files\Audio;
use Throwable;

/**
 * «Ditar» (docs/DECISOES.md, "Falar em vez de escrever"): the recording comes
 * in, the text goes back to the box, where the person reads it before sending.
 * The audio is not kept.
 */
class DictationController extends Controller
{
    public function __invoke(Request $request, BudgetGuard $budget): JsonResponse
    {
        $request->validate(['audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,video/webm']]);
        $provider = (string) config('ai.default');

        if (! Dictation::isFaked() && (blank(config("ai.providers.{$provider}.key")) || ! $budget->hasRoom())) {
            return response()->json(['message' => 'Ditar precisa do modelo de IA, que não está disponível agora.'], 503);
        }

        $file = $request->file('audio');
        $model = (string) config('agents.suggestions_model') ?: null;

        try {
            $response = (new Dictation)->prompt('Transcreve o que a pessoa ditou.', [Audio::fromBase64(base64_encode((string) $file->get()), (string) $file->getMimeType())], provider: $provider, model: $model, timeout: 60);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Não foi possível transcrever. Tente de novo ou escreva.'], 502);
        }

        $budget->charge('dictation', $response->meta->provider ?? $provider, $response->meta->model ?? $model, (int) $response->usage->inputTokens, (int) $response->usage->outputTokens);

        return response()->json(['text' => trim($response->text)]);
    }
}
