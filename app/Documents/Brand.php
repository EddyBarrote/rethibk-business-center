<?php

namespace App\Documents;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant's look on generated documents: name, colours, font, logo and
 * footer (settings.brand, edited in Definições > Marca).
 */
final readonly class Brand
{
    public const DEFAULT_COLOR = '#1E3A5F';

    public const DEFAULT_FONT = 'Calibri';

    /**
     * Fonts offered for Word, PowerPoint and Excel, by name: the reader's
     * Office shows them if installed. PDF embeds the font when its files are
     * in resources/fonts, and otherwise keeps to the family in DejaVu.
     */
    public const FONTS = [
        'Calibri' => 'sans', 'Aptos' => 'sans', 'Arial' => 'sans', 'Helvetica' => 'sans', 'Segoe UI' => 'sans',
        'Verdana' => 'sans', 'Montserrat' => 'sans', 'Open Sans' => 'sans', 'Roboto' => 'sans', 'Poppins' => 'sans', 'Space Grotesk' => 'sans',
        'Georgia' => 'serif', 'Cambria' => 'serif', 'Garamond' => 'serif', 'Times New Roman' => 'serif',
    ];

    public function __construct(
        public string $name,
        public string $color,
        public ?string $logoPath,
        public ?string $footer,
        public ?string $accent = null,
        public string $font = self::DEFAULT_FONT,
        /** @var array<string, array{label: string, path: string}> images documents can show (signature, stamp…), by key: settings.brand.assets */
        public array $assets = [],
    ) {}

    public static function current(): self
    {
        $tenant = Tenant::current();
        $settings = (array) data_get($tenant?->settings, 'brand', []);
        $color = (string) ($settings['color'] ?? '');
        $accent = (string) ($settings['accent_color'] ?? '');
        $font = (string) ($settings['font'] ?? '');
        $logo = $settings['logo_path'] ?? null;
        $assets = [];

        foreach ((array) ($settings['assets'] ?? []) as $asset) {
            if (is_array($asset) && is_string($asset['path'] ?? null) && Storage::disk('local')->exists($asset['path'])) {
                $assets[(string) $asset['key']] = ['label' => (string) ($asset['label'] ?? $asset['key']), 'path' => Storage::disk('local')->path($asset['path'])];
            }
        }

        return new self(
            name: (string) ($tenant->name ?? config('app.name')),
            color: self::validColor($color) ? strtoupper($color) : self::DEFAULT_COLOR,
            logoPath: is_string($logo) && Storage::disk('local')->exists($logo) ? Storage::disk('local')->path($logo) : null,
            footer: isset($settings['footer']) ? (string) $settings['footer'] : null,
            accent: self::validColor($accent) ? strtoupper($accent) : null,
            font: array_key_exists($font, self::FONTS) ? $font : self::DEFAULT_FONT,
            assets: $assets,
        );
    }

    public static function validColor(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    /** The second colour, or the main one when there is none. */
    public function accentColor(): string
    {
        return $this->accent ?? $this->color;
    }

    /** The PDF font: the brand font when it can be embedded, else DejaVu in its family. */
    public function pdfFont(): string
    {
        if ($this->pdfFontFiles() !== null) {
            return $this->font;
        }

        return self::FONTS[$this->font] === 'serif' ? 'DejaVu Serif' : 'DejaVu Sans';
    }

    /**
     * The brand font's files for embedding in PDFs, when they ship with the
     * app (resources/fonts/SpaceGrotesk-Regular.ttf and -Bold.ttf).
     *
     * @return array{regular: string, bold: string}|null
     */
    public function pdfFontFiles(): ?array
    {
        $base = resource_path('fonts/'.str_replace(' ', '', $this->font));
        $files = ['regular' => $base.'-Regular.ttf', 'bold' => $base.'-Bold.ttf'];

        return is_file($files['regular']) && is_file($files['bold']) ? $files : null;
    }

    /**
     * The brand as facts for skills ({{marca.cor}} and friends) and for
     * people who want to reuse it elsewhere.
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        return [
            'nome' => $this->name,
            'cor' => $this->color,
            'cor_secundaria' => $this->accent ?? 'nenhuma (usa-se a principal)',
            'fonte' => $this->font,
            'logotipo' => $this->logoPath !== null ? 'carregado (aparece sozinho nos ficheiros)' : 'nenhum carregado',
            'rodape' => $this->footer ?? $this->name,
            'imagens' => $this->assets === [] ? 'nenhuma' : implode(', ', array_map(fn (string $key, array $a) => "{$a['label']} (asset:{$key})", array_keys($this->assets), $this->assets)),
        ];
    }

    /** "1E3A5F", as Office libraries want it. */
    public function hex(): string
    {
        return ltrim($this->color, '#');
    }

    /** A pale tint of the colour for table headers and bands. */
    public function tint(float $amount = 0.88): string
    {
        [$r, $g, $b] = sscanf($this->hex(), '%02x%02x%02x') ?: [0, 0, 0];
        $mix = fn (int $c) => (int) round($c + (255 - $c) * $amount);

        return sprintf('%02X%02X%02X', $mix((int) $r), $mix((int) $g), $mix((int) $b));
    }

    /**
     * Fills {{marca.cor}}, {{marca.fonte}}… in a skill with today's values
     * from Definições > Marca, and {{marca}} with all of them as a list, so
     * a skill never goes stale when the brand changes.
     */
    public function fill(string $text): string
    {
        $facts = $this->facts();
        $labels = ['nome' => 'Nome', 'cor' => 'Cor principal', 'cor_secundaria' => 'Cor secundária', 'fonte' => 'Fonte', 'logotipo' => 'Logótipo', 'rodape' => 'Rodapé', 'imagens' => 'Imagens'];
        $list = collect($facts)->map(fn (string $value, string $key) => '- '.$labels[$key].': '.$value)->implode("\n");

        return (string) preg_replace_callback('/\{\{\s*marca(?:\.([a-z_]+))?\s*\}\}/u', fn (array $m) => ($m[1] ?? '') === ''
            ? $list
            : ($facts[$m[1]] ?? $m[0]), $text);
    }

    public function logoDataUri(): ?string
    {
        if ($this->logoPath === null) {
            return null;
        }

        $mime = mime_content_type($this->logoPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($this->logoPath));
    }
}
