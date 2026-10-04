<?php

namespace App\Documents;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant's look on generated documents: name, main colour and logo
 * (settings.brand, edited in Definições > Marca).
 */
final readonly class Brand
{
    public const DEFAULT_COLOR = '#1E3A5F';

    public function __construct(
        public string $name,
        public string $color,
        public ?string $logoPath,
        public ?string $footer,
    ) {}

    public static function current(): self
    {
        $tenant = Tenant::current();
        $settings = (array) data_get($tenant?->settings, 'brand', []);
        $color = (string) ($settings['color'] ?? '');
        $logo = $settings['logo_path'] ?? null;

        return new self(
            name: (string) ($tenant->name ?? config('app.name')),
            color: preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : self::DEFAULT_COLOR,
            logoPath: is_string($logo) && Storage::disk('local')->exists($logo) ? Storage::disk('local')->path($logo) : null,
            footer: isset($settings['footer']) ? (string) $settings['footer'] : null,
        );
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

    public function logoDataUri(): ?string
    {
        if ($this->logoPath === null) {
            return null;
        }

        $mime = mime_content_type($this->logoPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($this->logoPath));
    }
}
