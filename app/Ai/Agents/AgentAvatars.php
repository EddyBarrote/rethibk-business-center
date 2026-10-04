<?php

namespace App\Ai\Agents;

use App\Models\Agent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Image;
use RuntimeException;

/**
 * The agent's photo (docs/CAPACIDADES.md): uploaded by an admin or drawn by
 * the image model when a key is configured. Without one, the interface shows
 * the agent's initials. Files live on the private disk and are served by
 * AgentAvatarController to people of the same tenant.
 */
final class AgentAvatars
{
    public const DISK = 'local';

    public function upload(Agent $agent, UploadedFile $file): void
    {
        $this->replace($agent, (string) $file->get(), $file->extension() ?: 'png');
    }

    /**
     * Ask the image model for a portrait that fits the agent's role and
     * personality. Throws when no image provider has a key.
     */
    public function generate(Agent $agent): void
    {
        if (! self::canGenerate()) {
            throw new RuntimeException('Para gerar fotos falta a chave do provedor de imagens no .env (por exemplo GEMINI_API_KEY).');
        }

        $response = Image::of($this->prompt($agent))->square()->timeout(90)->generate();
        $image = $response->firstImage();

        $this->replace($agent, $image->content(), Str::after($image->mime(), 'image/') ?: 'png');
    }

    public function remove(Agent $agent): void
    {
        if ($agent->avatar_path !== null) {
            Storage::disk(self::DISK)->delete($agent->avatar_path);
        }

        $agent->forceFill(['avatar_path' => null])->save();
    }

    public static function canGenerate(): bool
    {
        if (Image::isFaked()) {
            return true;
        }

        $provider = (string) config('ai.default_for_images');

        return filled(config("ai.providers.{$provider}.key"));
    }

    public function prompt(Agent $agent): string
    {
        return trim(implode(' ', array_filter([
            'Retrato profissional, ombros para cima, de um colega de trabalho numa empresa moçambicana moderna.',
            "Chama-se {$agent->name}".($agent->title ? " e trabalha como {$agent->title}." : '.'),
            $agent->personality ? 'Personalidade: '.Str::limit($agent->personality, 300) : null,
            'Ilustração digital limpa e acolhedora, fundo liso de cor suave, luz natural, expressão simpática, roupa de escritório.',
            'Sem texto, sem logótipos, sem molduras.',
        ])));
    }

    private function replace(Agent $agent, string $bytes, string $extension): void
    {
        $extension = in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? $extension : 'png';
        $path = "avatars/{$agent->tenant_id}/{$agent->id}-".Str::random(10).".{$extension}";

        Storage::disk(self::DISK)->put($path, $bytes);

        if ($agent->avatar_path !== null) {
            Storage::disk(self::DISK)->delete($agent->avatar_path);
        }

        $agent->forceFill(['avatar_path' => $path])->save();
    }
}
