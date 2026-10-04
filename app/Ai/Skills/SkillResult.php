<?php

namespace App\Ai\Skills;

/**
 * What a skill returns to the model (content) and to the run log (data).
 */
final readonly class SkillResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public bool $ok,
        public string $content,
        public ?array $data = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data): self
    {
        return new self(true, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $data);
    }

    public static function text(string $text): self
    {
        return new self(true, $text);
    }

    public static function error(string $message): self
    {
        return new self(false, 'Erro: '.$message);
    }
}
