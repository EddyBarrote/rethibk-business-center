<?php

namespace App\Ai\Skills;

use App\Models\AuditLog;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Importing skills made elsewhere (docs/CAPACIDADES.md, "Importar skills"):
 * the uploads are read and kept aside, the person sees what came in and what
 * was left out, and only then are the skills created. An imported skill is
 * not given to any agent; a person chooses who uses it.
 */
final class SkillImports
{
    private const DISK = 'local';

    public function __construct(private readonly SkillPackageReader $reader, private readonly SkillFiles $files) {}

    /**
     * Read the uploads and keep them aside until the person confirms.
     *
     * @param  list<UploadedFile>  $uploads
     */
    public function stage(int $tenantId, User $user, array $uploads): string
    {
        $this->prune($tenantId);

        $token = Str::lower((string) Str::ulid());
        $folder = $this->folder($tenantId, $token);
        $skills = [];
        $problems = [];

        foreach ($uploads as $upload) {
            $read = $this->reader->read($upload);
            $problems = [...$problems, ...$read['problems']];

            foreach ($read['blobs'] as $blob => $bytes) {
                Storage::disk(self::DISK)->put("{$folder}/{$blob}", $bytes);
            }

            $skills = [...$skills, ...$read['skills']];
        }

        $taken = Skill::query()->pluck('key')->all();

        foreach ($skills as $index => &$skill) {
            $skill['index'] = $index;
            $skill['key_taken'] = in_array($skill['key'], $taken, true);
            $skill['key'] = $this->freeKey($skill['key'], $taken);
            $taken[] = $skill['key'];
        }

        unset($skill);

        Storage::disk(self::DISK)->put("{$folder}/manifest.json", (string) json_encode([
            'user_id' => $user->id,
            'created_at' => now()->toIso8601String(),
            'skills' => $skills,
            'problems' => $problems,
        ]));

        return $token;
    }

    /**
     * @return array{user_id: int, created_at: string, skills: list<array<string, mixed>>, problems: list<array{source: string, reason: string}>}|null
     */
    public function load(int $tenantId, string $token): ?array
    {
        if (preg_match('/^[0-9a-z]{26}$/', $token) !== 1) {
            return null;
        }

        $manifest = Storage::disk(self::DISK)->get($this->folder($tenantId, $token).'/manifest.json');

        return $manifest === null ? null : json_decode($manifest, true);
    }

    /**
     * Create the chosen skills with the person's edits, and drop the rest.
     *
     * @param  array<int, array{key: string, name: string, description: string}>  $choices  by index
     * @return list<Skill>
     */
    public function commit(int $tenantId, string $token, User $user, array $choices): array
    {
        $manifest = $this->load($tenantId, $token) ?? [];
        $folder = $this->folder($tenantId, $token);
        $created = [];

        foreach ($manifest['skills'] ?? [] as $package) {
            $choice = $choices[$package['index']] ?? null;

            if ($choice === null) {
                continue;
            }

            $skill = new Skill([
                'key' => $choice['key'],
                'name' => $choice['name'],
                'description' => $choice['description'],
                'instructions' => $package['instructions'] !== '' ? $package['instructions'] : "# {$choice['name']}",
                'is_enabled' => true,
                'all_agents' => false,
            ]);
            $skill->forceFill(['created_by_user_id' => $user->id])->save();

            foreach ($package['files'] as $file) {
                $path = Storage::disk(self::DISK)->path("{$folder}/{$file['blob']}");

                if (! is_file($path)) {
                    continue;
                }

                $extension = Str::lower(pathinfo($file['filename'], PATHINFO_EXTENSION));
                $mime = MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream';
                $this->files->attach($skill, new UploadedFile($path, basename($file['filename']), $mime, null, true), $file['filename']);
            }

            AuditLog::record($user, 'skill.imported', [
                'key' => $skill->key,
                'source' => $package['source'],
                'files' => array_column($package['files'], 'filename'),
                'left_out' => array_column($package['left_out'], 'filename'),
            ], subject: $skill);

            $created[] = $skill;
        }

        $this->discard($tenantId, $token);

        return $created;
    }

    public function discard(int $tenantId, string $token): void
    {
        if (preg_match('/^[0-9a-z]{26}$/', $token) !== 1) {
            return;
        }

        Storage::disk(self::DISK)->deleteDirectory($this->folder($tenantId, $token));
    }

    /**
     * @param  list<string>  $taken
     */
    private function freeKey(string $key, array $taken): string
    {
        $candidate = $key;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = Str::limit($key, 60, '')."-{$n}";
        }

        return $candidate;
    }

    /** Imports nobody confirmed within a day go away. */
    private function prune(int $tenantId): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($disk->directories("skill-imports/{$tenantId}") as $directory) {
            if (! $disk->exists("{$directory}/manifest.json") || $disk->lastModified("{$directory}/manifest.json") < now()->subDay()->getTimestamp()) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    private function folder(int $tenantId, string $token): string
    {
        return "skill-imports/{$tenantId}/{$token}";
    }
}
