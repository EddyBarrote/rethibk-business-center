<?php

namespace App\Documents;

use App\Models\PlatformSkillFile;
use App\Models\Skill;
use App\Models\SkillFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A kind of document (proposta, relatório mensal, carta de cobrança…) as a
 * skill says it: the instructions say what goes in it, and an optional block
 * at the top says how it looks (docs/CAPACIDADES.md, "Tipos de documento"):
 *
 *     ---
 *     modelo: relatorio
 *     estilo: estilo.css
 *     logotipo: obrigatório
 *     asset assinatura: Assinatura de quem assina a proposta
 *     asset carimbo: Carimbo da empresa
 *     ---
 *
 * Images come from the skill's files or from the brand (Definições › Marca),
 * by key: ![Assinatura](asset:assinatura). What is missing the agent asks a
 * person to upload, and it is kept in the brand for next time.
 */
final readonly class DocumentType
{
    private const MAX_CSS = 50_000;

    /**
     * @param  array<string, string>  $required  asset key => what it is
     * @param  array<string, string>  $images  asset key => absolute path, from the skill's files
     */
    public function __construct(
        public ?int $skillId,
        public string $name,
        public ?DocumentTemplate $template = null,
        public ?string $css = null,
        public array $required = [],
        public array $images = [],
        public bool $needsLogo = false,
    ) {}

    public static function fromSkill(Skill $skill): self
    {
        $config = self::config($skill->body());
        $files = $skill->platformSkill !== null ? $skill->platformSkill->files : $skill->files;
        $images = [];
        $css = null;

        foreach ($files as $file) {
            /** @var SkillFile|PlatformSkillFile $file */
            if (str_starts_with((string) $file->mime, 'image/') && Storage::disk('local')->exists($file->path)) {
                $images[self::key($file->filename)] = Storage::disk('local')->path($file->path);
            }

            if (filled($config['estilo'] ?? null) && mb_strtolower($file->filename) === mb_strtolower((string) $config['estilo']) && Storage::disk('local')->exists($file->path)) {
                $css = self::safeCss((string) Storage::disk('local')->get($file->path));
            }
        }

        $required = [];

        foreach ($config as $key => $value) {
            if (str_starts_with($key, 'asset ')) {
                $required[self::key(substr($key, 6))] = $value !== '' ? $value : trim(substr($key, 6));
            }
        }

        return new self(
            skillId: $skill->id,
            name: $skill->displayName(),
            template: DocumentTemplate::tryFrom(Str::lower(Str::ascii((string) ($config['modelo'] ?? '')))),
            css: $css,
            required: $required,
            images: $images,
            needsLogo: in_array(Str::lower(Str::ascii((string) ($config['logotipo'] ?? ''))), ['obrigatorio', 'sim', 'yes', 'true'], true),
        );
    }

    /**
     * The "chave: valor" lines between --- at the top of a skill.
     *
     * @return array<string, string>
     */
    public static function config(string $body): array
    {
        if (preg_match('/\A\s*---\R(.*?)\R---/s', $body, $match) !== 1) {
            return [];
        }

        $config = [];

        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (preg_match('/^\s*([^:#][^:]*?)\s*:\s*(.*?)\s*$/u', $line, $m) === 1) {
                $config[Str::lower($m[1])] = $m[2];
            }
        }

        return $config;
    }

    /** "Assinatura do Director.png" → "assinatura-do-director". */
    public static function key(string $name): string
    {
        $base = preg_replace('/\.(png|jpe?g|gif|svg|webp)$/i', '', trim($name)) ?? $name;

        return Str::slug($base);
    }

    /**
     * Every image the document can show: the skill's first, then the brand's.
     *
     * @return array<string, string> key => absolute path
     */
    public function imagesWith(Brand $brand): array
    {
        return [...array_map(fn (array $asset) => $asset['path'], $brand->assets), ...$this->images];
    }

    /**
     * What the document needs and nobody has uploaded yet: the skill's
     * required assets, the asset:… images in the text, and the logo when
     * the skill asks for it. Assets a person said to do without are skipped.
     *
     * @param  list<string>  $without
     * @return list<array{key: string, label: string}>
     */
    public function missing(string $markdown, Brand $brand, array $without = []): array
    {
        $available = $this->imagesWith($brand);
        $wanted = $this->required;

        foreach (self::referenced($markdown) as $key) {
            $wanted[$key] ??= Str::headline($key);
        }

        $without = array_map(self::key(...), $without);
        $missing = [];

        if ($this->needsLogo && $brand->logoPath === null && ! in_array('logotipo', $without, true)) {
            $missing[] = ['key' => 'logotipo', 'label' => 'Logótipo da empresa'];
        }

        foreach ($wanted as $key => $label) {
            if (! isset($available[$key]) && ! in_array($key, $without, true)) {
                $missing[] = ['key' => $key, 'label' => $label];
            }
        }

        return $missing;
    }

    /**
     * The keys of the asset:… images in Markdown.
     *
     * @return list<string>
     */
    public static function referenced(string $markdown): array
    {
        preg_match_all('/!\[[^\]]*\]\(\s*<?asset:([^)\s>]+)>?\s*\)/u', $markdown, $matches);

        return array_values(array_unique(array_map(self::key(...), $matches[1])));
    }

    /** A stylesheet that cannot close the style tag nor fetch anything. */
    private static function safeCss(string $css): string
    {
        $css = Str::limit($css, self::MAX_CSS, '');
        $css = str_replace(['</', '<!--', '-->'], '', $css);

        return (string) preg_replace(['/@import[^;]*;?/i', '/url\(\s*[\'"]?(?!data:)[^)]*\)/i'], ['', 'none'], $css);
    }
}
