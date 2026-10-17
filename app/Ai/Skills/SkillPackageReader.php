<?php

namespace App\Ai\Skills;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Reads skills made for Claude, Codex and similar tools (docs/CAPACIDADES.md,
 * "Importar skills"): a SKILL.md with name and description at the top, or a
 * .zip / .skill folder holding SKILL.md and the files next to it. One archive
 * may hold several skill folders.
 *
 * Nothing in a package is ever run: scripts are left out and listed, and the
 * person sees what came in and what did not before anything is saved.
 */
final class SkillPackageReader
{
    /** Kept as skill files: what the editor takes, plus images for document types. */
    public const KEPT = ['md', 'txt', 'csv', 'json', 'xml', 'html', 'pdf', 'docx', 'xlsx', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public const SCRIPTS = ['py', 'sh', 'bash', 'zsh', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'rb', 'php', 'pl', 'ps1', 'bat', 'cmd', 'exe', 'bin', 'dll', 'so', 'dylib', 'jar', 'go', 'rs', 'java', 'swift', 'lua', 'r', 'applescript', 'scpt', 'command', 'app', 'deb', 'msi', 'wasm'];

    /** The top-of-skill lines document types read (App\Documents\DocumentType). */
    private const DOCUMENT_KEYS = ['modelo', 'estilo', 'logotipo'];

    public const MAX_FILE = 10 * 1024 * 1024;

    public const MAX_ARCHIVE = 50 * 1024 * 1024;

    private const MAX_ENTRIES = 5000;

    private const MAX_FILES_PER_SKILL = 40;

    private const MAX_INSTRUCTIONS = 60_000;

    private const MAX_DESCRIPTION = 1000;

    /**
     * @return array{skills: list<array<string, mixed>>, problems: list<array{source: string, reason: string}>, blobs: array<string, string>}
     *                                                                                                                                        blobs maps a blob id to its bytes; the skills point at them
     */
    public function read(UploadedFile $upload): array
    {
        $name = $upload->getClientOriginalName();
        $extension = Str::lower($upload->getClientOriginalExtension());

        if (in_array($extension, ['md', 'markdown', 'txt'], true)) {
            $text = (string) file_get_contents($upload->getRealPath());
            $folder = Str::lower(pathinfo($name, PATHINFO_FILENAME)) === 'skill' ? '' : pathinfo($name, PATHINFO_FILENAME);

            return ['skills' => [$this->skill($name, $folder, $text, [])], 'problems' => [], 'blobs' => []];
        }

        if (! in_array($extension, ['zip', 'skill'], true)) {
            return ['skills' => [], 'problems' => [['source' => $name, 'reason' => 'Formato não reconhecido: envie um SKILL.md, um .zip ou um .skill.']], 'blobs' => []];
        }

        return $this->archive($upload->getRealPath(), $name);
    }

    /**
     * @return array{skills: list<array<string, mixed>>, problems: list<array{source: string, reason: string}>, blobs: array<string, string>}
     */
    private function archive(string $path, string $source): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return ['skills' => [], 'problems' => [['source' => $source, 'reason' => 'Não foi possível abrir o arquivo (não é um zip válido).']], 'blobs' => []];
        }

        /** @var array<string, array{index: int, size: int}> $entries normalised path => entry */
        $entries = [];
        $total = 0; // bytes read so far: only what comes in is ever unpacked

        for ($i = 0; $i < min($zip->numFiles, self::MAX_ENTRIES); $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || str_ends_with($stat['name'], '/')) {
                continue;
            }

            $normalised = self::normalise($stat['name']);

            if ($normalised === null) {
                continue;
            }

            $entries[$normalised] = ['index' => $i, 'size' => (int) $stat['size']];
        }

        // Every folder with a SKILL.md is a skill; the deepest one owns its files.
        $roots = [];

        foreach (array_keys($entries) as $entry) {
            if (Str::lower(basename($entry)) === 'skill.md') {
                $roots[] = dirname($entry) === '.' ? '' : dirname($entry);
            }
        }

        if ($roots === []) {
            $zip->close();

            return ['skills' => [], 'problems' => [['source' => $source, 'reason' => 'Não há nenhum SKILL.md dentro do arquivo.']], 'blobs' => []];
        }

        usort($roots, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $skills = [];
        $blobs = [];
        $claimed = [];

        foreach ($roots as $root) {
            $prefix = $root === '' ? '' : $root.'/';
            $skillMd = collect(array_keys($entries))->first(fn (string $e) => Str::lower($e) === Str::lower($prefix.'skill.md'));
            $text = $entries[$skillMd]['size'] > self::MAX_FILE ? '' : (string) $zip->getFromIndex($entries[$skillMd]['index']);
            $claimed[$skillMd] = true;
            $files = [];

            foreach ($entries as $entry => $info) {
                if (isset($claimed[$entry]) || ($prefix !== '' && ! str_starts_with($entry, $prefix))) {
                    continue;
                }

                $claimed[$entry] = true;
                $relative = substr($entry, strlen($prefix));
                $verdict = $this->verdict($relative, $info['size']) ?? ($total + $info['size'] > self::MAX_ARCHIVE ? 'Passa do limite de 50 MB por importação.' : null);

                if ($verdict === null) {
                    $total += $info['size'];
                    $blob = (string) Str::ulid();
                    $blobs[$blob] = (string) $zip->getFromIndex($info['index']);
                    $files[] = ['filename' => $relative, 'size' => $info['size'], 'blob' => $blob];
                } else {
                    $files[] = ['filename' => $relative, 'size' => $info['size'], 'reason' => $verdict];
                }
            }

            $skills[] = $this->skill($source, $root === '' ? pathinfo($source, PATHINFO_FILENAME) : basename($root), $text, $files);
        }

        $zip->close();

        // The order they sit in the archive reads better than deepest-first.
        usort($skills, fn (array $a, array $b) => strcmp($a['folder'], $b['folder']));

        return ['skills' => $skills, 'problems' => [], 'blobs' => $blobs];
    }

    /**
     * Why a file stays out, or null when it comes in.
     */
    private function verdict(string $relative, int $size): ?string
    {
        $extension = Str::lower(pathinfo($relative, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, self::SCRIPTS, true) => 'Script: a plataforma não corre código que venha numa skill.',
            ! in_array($extension, self::KEPT, true) => $extension === '' ? 'Ficheiro sem extensão.' : "Tipo .{$extension} não suportado nas skills.",
            $size > self::MAX_FILE => 'Passa de 10 MB.',
            default => null,
        };
    }

    /**
     * A skill as the preview shows it, from the SKILL.md text and its files.
     *
     * @param  list<array{filename: string, size: int, blob?: string, reason?: string}>  $files
     * @return array<string, mixed>
     */
    private function skill(string $source, string $folder, string $text, array $files): array
    {
        $text = mb_convert_encoding(str_replace("\r\n", "\n", $text), 'UTF-8', 'UTF-8');
        [$meta, $body] = self::frontMatter($text);
        $notes = [];

        $key = Str::limit(Str::slug($meta['name'] ?? $folder), 64, '');
        $key = $key !== '' ? $key : 'skill-importada';
        $description = trim((string) preg_replace('/\s+/', ' ', $meta['description'] ?? ''));

        if ($description === '') {
            $notes[] = 'Não traz descrição («quando usar»): escreva-a antes de importar, é por ela que o agente decide.';
        } elseif (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $description = Str::limit($description, self::MAX_DESCRIPTION - 1, '…');
            $notes[] = 'A descrição foi encurtada para 1000 caracteres.';
        }

        // Lines a document type reads stay at the top, as the platform expects them.
        $kept = array_filter($meta, fn (string $value, string $k) => in_array($k, self::DOCUMENT_KEYS, true) || str_starts_with($k, 'asset '), ARRAY_FILTER_USE_BOTH);
        $dropped = array_values(array_diff(array_keys($meta), ['name', 'description', ...array_keys($kept)]));
        $instructions = trim($body);

        if ($kept !== []) {
            $instructions = "---\n".collect($kept)->map(fn (string $v, string $k) => "{$k}: {$v}")->implode("\n")."\n---\n\n".$instructions;
        }

        if ($dropped !== []) {
            $notes[] = 'Ficaram de fora do topo: '.implode(', ', $dropped).' (são do Claude/Codex e a plataforma não as usa).';
        }

        if (trim($body) === '') {
            $notes[] = 'O SKILL.md não tem instruções.';
        }

        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
            $instructions = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS);
            $notes[] = 'As instruções passam de 60 000 caracteres e foram cortadas no fim.';
        }

        $kept = array_values(array_filter($files, fn (array $f) => isset($f['blob'])));
        $left = array_values(array_filter($files, fn (array $f) => isset($f['reason'])));

        if (count($kept) > self::MAX_FILES_PER_SKILL) {
            foreach (array_splice($kept, self::MAX_FILES_PER_SKILL) as $extra) {
                $left[] = ['filename' => $extra['filename'], 'size' => $extra['size'], 'reason' => 'Mais de 40 ficheiros numa skill.'];
            }
        }

        $scripts = array_filter($left, fn (array $f) => in_array(Str::lower(pathinfo($f['filename'], PATHINFO_EXTENSION)), self::SCRIPTS, true));

        if ($scripts !== []) {
            $notes[] = 'As instruções podem mandar correr scripts que ficaram de fora; reveja-as para dizerem como o agente faz o trabalho com as capacidades da plataforma.';
        }

        return [
            'source' => $source,
            'folder' => $folder,
            'key' => $key,
            'name' => self::title($body, $meta['name'] ?? $folder),
            'description' => $description,
            'instructions' => $instructions,
            'files' => $kept,
            'left_out' => $left,
            'notes' => $notes,
        ];
    }

    /**
     * The block between --- at the top, as simple YAML: plain, quoted and
     * folded (> or |) values. Nested maps are kept as one dropped key.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public static function frontMatter(string $text): array
    {
        if (preg_match('/\A\x{FEFF}?\s*---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/su', $text, $match) !== 1) {
            return [[], $text];
        }

        /** @var list<array{key: string, style: string|null, lines: list<string>}> $blocks */
        $blocks = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([A-Za-zÀ-ÿ0-9_][A-Za-zÀ-ÿ0-9_ .-]*?)\s*:(?:\s+(.*)|\s*)$/u', $line, $m) === 1 && preg_match('/^\s/', $line) !== 1) {
                $first = trim($m[2] ?? '');
                $style = preg_match('/^([>|])[-+]?$/', $first, $s) === 1 ? $s[1] : null;
                $blocks[] = ['key' => Str::lower(trim($m[1])), 'style' => $style, 'lines' => $style !== null ? [] : [$first]];
            } elseif ($blocks !== [] && ! str_starts_with(ltrim($line), '#')) {
                $blocks[count($blocks) - 1]['lines'][] = $line;
            }
        }

        $meta = [];

        foreach ($blocks as $block) {
            $lines = array_map('trim', $block['lines']);
            $value = $block['style'] === '|' ? implode("\n", $lines) : implode(' ', array_filter($lines, fn (string $l) => $l !== ''));
            $meta[$block['key']] = trim(self::unquote(trim($value)));
        }

        return [$meta, substr($text, strlen($match[0]))];
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * The first heading when it is short enough to be a name; otherwise the
     * kebab-case name made readable ("paytech-brand" → "Paytech brand").
     */
    private static function title(string $body, string $name): string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $m) === 1 && mb_strlen(trim($m[1])) <= 80) {
            return trim(str_replace(['**', '`'], '', $m[1]));
        }

        $readable = trim(str_replace(['-', '_'], ' ', $name));

        return $readable === '' ? 'Skill importada' : Str::ucfirst($readable);
    }

    /**
     * A path inside the archive made safe, or null for what is never a skill
     * file: macOS metadata, hidden files, and paths that climb out.
     */
    private static function normalise(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        $parts = array_values(array_filter(explode('/', $name), fn (string $p) => $p !== '' && $p !== '.'));

        if ($parts === [] || in_array('..', $parts, true) || $parts[0] === '__MACOSX') {
            return null;
        }

        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) {
                return null;
            }
        }

        return implode('/', $parts);
    }
}
