<?php

namespace App\Attachments;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * What a company accepts in conversations: size, how many files per message,
 * the longest recording and the types (tenants.settings.attachments, edited
 * in Definições › Anexos; defaults in config/attachments.php).
 */
final readonly class AttachmentLimits
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public int $maxMb,
        public int $maxFiles,
        public int $audioMaxMinutes,
        public array $types,
    ) {}

    public static function current(?Tenant $tenant = null): self
    {
        $tenant ??= Tenant::current();
        $saved = (array) data_get($tenant?->settings, 'attachments', []);
        $defaults = (array) config('attachments.defaults');
        $ceiling = (array) config('attachments.ceiling');
        $known = array_keys((array) config('attachments.types'));

        $int = fn (string $key) => max(1, min((int) $ceiling[$key], (int) ($saved[$key] ?? $defaults[$key])));
        $types = array_values(array_intersect($known, (array) ($saved['types'] ?? $defaults['types'])));

        return new self($int('max_mb'), $int('max_files'), $int('audio_max_minutes'), $types);
    }

    /**
     * What the file repository accepts (docs/DECISOES.md, 14.10.2026):
     * documents, spreadsheets, presentations, text and images, up to
     * config('knowledge.max_upload_mb'), many at once.
     */
    public static function repository(): self
    {
        return new self(
            max(1, (int) config('knowledge.max_upload_mb', 100)),
            max(1, (int) config('knowledge.max_upload_files', 200)),
            0,
            ['pdf', 'office', 'text', 'image'],
        );
    }

    /**
     * The limits an upload follows, by where it goes.
     */
    public static function forSource(string $source): self
    {
        return $source === 'repository' ? self::repository() : self::current();
    }

    public function maxBytes(): int
    {
        return $this->maxMb * 1024 * 1024;
    }

    /**
     * The type a file belongs to, by its extension, if the company accepts it.
     */
    public function typeFor(string $filename): ?string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach ($this->types as $type) {
            if (in_array($extension, (array) config("attachments.types.{$type}.extensions"), true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether the bytes are what the type says (a .pdf that is a PDF).
     */
    public static function contentMatches(string $type, string $sniffedMime): bool
    {
        foreach ((array) config("attachments.types.{$type}.mimes") as $prefix) {
            if (str_starts_with($sniffedMime, (string) $prefix)) {
                return true;
            }
        }

        // Plain text files are often sniffed as something close to text.
        return $type === 'text' && ! str_contains($sniffedMime, 'x-executable');
    }

    /**
     * Extensions accepted, for the file picker.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_values(array_merge(...array_map(fn (string $type) => (array) config("attachments.types.{$type}.extensions"), $this->types ?: ['_'])));
    }

    /**
     * Size of each upload request: under PHP's own limits, so any file size works.
     */
    public static function chunkBytes(): int
    {
        $limit = min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size')));
        $limit = $limit > 0 ? (int) floor($limit * 0.8) : PHP_INT_MAX;

        return max(256 * 1024, min((int) config('attachments.chunk_max_bytes'), $limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function forScreen(): array
    {
        return [
            'max_mb' => $this->maxMb,
            'max_files' => $this->maxFiles,
            'audio_max_seconds' => $this->audioMaxMinutes * 60,
            'types' => $this->types,
            'extensions' => $this->extensions(),
            'chunk_bytes' => self::chunkBytes(),
        ];
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $number = (int) $value;

        return match (Str::lower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
