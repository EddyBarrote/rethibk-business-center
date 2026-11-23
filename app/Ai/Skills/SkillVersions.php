<?php

namespace App\Ai\Skills;

use App\Models\PlatformAdmin;
use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use App\Models\PlatformSkillVersion;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\SkillVersion;
use App\Models\User;
use App\Support\LineDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Versions of a skill (docs/DECISOES.md, "Latência, taxa de erro e versões
 * das skills"): each change to what agents read in it (name, «quando usar»,
 * instructions, files) keeps a numbered, immutable copy, with who made it.
 * Restoring an old version writes it back as a new one; nothing is removed.
 *
 * Agents always read the current content (no pinning): the history is there
 * to see what changed and to go back.
 *
 * Callers record after each change. Before changing a skill that has no
 * version yet (made before versions existed, or by a path that kept none),
 * baseline() keeps what it holds as it is.
 */
final class SkillVersions
{
    /**
     * Keep the skill as it is now, when that differs from its latest version
     * (or always, for a restore).
     */
    public function record(Skill|PlatformSkill $skill, User|PlatformAdmin|null $author, ?int $restoredFrom = null): SkillVersion|PlatformSkillVersion|null
    {
        if ($skill instanceof Skill && $skill->platform_skill_id !== null) {
            return null;
        }

        return DB::transaction(function () use ($skill, $author, $restoredFrom) {
            $latest = $skill->versions()->lockForUpdate()->first();
            $content = $this->content($skill);

            if ($latest !== null && $restoredFrom === null && ! $this->differs($latest->content(), $content)) {
                return null;
            }

            return $skill->versions()->create([
                'number' => ($latest->number ?? 0) + 1,
                'key' => $skill->key,
                ...$content,
                'restored_from' => $restoredFrom,
                ...($skill instanceof PlatformSkill
                    ? ['created_by_admin_id' => $author instanceof PlatformAdmin ? $author->id : null]
                    : ['created_by_user_id' => $author instanceof User ? $author->id : null]),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Before a change: what the skill holds now becomes its version 1 if it
     * has none, with no known author.
     */
    public function baseline(Skill|PlatformSkill $skill): void
    {
        if ($skill instanceof Skill && $skill->platform_skill_id !== null) {
            return;
        }

        if ($skill->versions()->reorder()->doesntExist()) {
            $skill->versions()->create([
                'number' => 1,
                'key' => $skill->key,
                ...$this->content($skill),
                'created_at' => $skill->updated_at ?? now(),
            ]);
        }
    }

    /**
     * Put an old version's content back (name, «quando usar», instructions
     * and files) and keep it as a new version. The key stays: agents and
     * other skills find the skill by it.
     */
    public function restore(Skill|PlatformSkill $skill, SkillVersion|PlatformSkillVersion $version, User|PlatformAdmin $author): SkillVersion|PlatformSkillVersion
    {
        $restored = DB::transaction(function () use ($skill, $version, $author) {
            $content = $version->content();

            $skill->forceFill(['name' => $content['name'], 'description' => $content['description'], 'instructions' => $content['instructions']])->save();

            // The stored files stay on disk for every version (SkillFiles::detach), so the rows only point at them again.
            $skill->files()->reorder()->delete();
            foreach ($content['files'] as $file) {
                $skill->files()->create(array_intersect_key($file, array_flip(['filename', 'path', 'mime', 'size', 'content'])));
            }

            $skill->unsetRelation('files');

            return $this->record($skill, $author, restoredFrom: $version->number);
        });

        if ($skill instanceof PlatformSkill) {
            // Global skills have no tenant, so no audit_logs row: the version itself records it, and the log too.
            Log::info('platform_skill.version_restored', ['platform_skill_id' => $skill->id, 'key' => $skill->key, 'restored_from' => $version->number, 'version' => $restored?->number, 'admin_id' => $author->id]);
        }

        return $restored ?? $version;
    }

    /**
     * What changed between a version and the skill as it is now: a line diff
     * of each text (the version is the old side) and the files by name.
     *
     * @return array{name: list<array{type: string, text: string}>, description: list<array{type: string, text: string}>, instructions: list<array{type: string, text: string}>, files: list<array{filename: string, status: 'added'|'removed'|'changed'|'same', size: int}>, changed: bool}
     */
    public function compare(Skill|PlatformSkill $skill, SkillVersion|PlatformSkillVersion $version): array
    {
        $old = $version->content();
        $new = $this->content($skill);

        $before = collect($old['files'])->keyBy('filename');
        $after = collect($new['files'])->keyBy('filename');
        $files = $before->keys()->merge($after->keys())->unique()->sort()->values()->map(function (string $name) use ($before, $after) {
            $was = $before->get($name);
            $is = $after->get($name);

            return [
                'filename' => $name,
                'status' => match (true) {
                    $was === null => 'added',
                    $is === null => 'removed',
                    $this->fileKey($was) !== $this->fileKey($is) => 'changed',
                    default => 'same',
                },
                'size' => (int) ($is['size'] ?? $was['size'] ?? 0),
            ];
        })->all();

        return [
            'name' => LineDiff::lines($old['name'], $new['name']),
            'description' => LineDiff::lines($old['description'], $new['description']),
            'instructions' => LineDiff::lines($old['instructions'], $new['instructions']),
            'files' => $files,
            'changed' => $this->differs($old, $new),
        ];
    }

    /**
     * What agents read in the skill now.
     *
     * @return array{name: string, description: string, instructions: string, files: list<array{filename: string, path: string, mime: string|null, size: int, content: string|null}>}
     */
    public function content(Skill|PlatformSkill $skill): array
    {
        return [
            'name' => (string) $skill->name,
            'description' => (string) $skill->description,
            'instructions' => (string) $skill->instructions,
            'files' => $skill->files()->get()->map(fn (SkillFile|PlatformSkillFile $file) => [
                'filename' => $file->filename,
                'path' => $file->path,
                'mime' => $file->mime,
                'size' => (int) $file->size,
                'content' => $file->content,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array{name: string, description: string, instructions: string, files: list<array<string, mixed>>}  $a
     * @param  array{name: string, description: string, instructions: string, files: list<array<string, mixed>>}  $b
     */
    private function differs(array $a, array $b): bool
    {
        $files = fn (array $files) => collect($files)->map(fn (array $file) => $this->fileKey($file))->sort()->values()->all();

        return $a['name'] !== $b['name'] || $a['description'] !== $b['description'] || $a['instructions'] !== $b['instructions']
            || $files($a['files']) !== $files($b['files']);
    }

    /**
     * A file is the same when its name, stored copy and text are.
     *
     * @param  array<string, mixed>  $file
     */
    private function fileKey(array $file): string
    {
        return implode('|', [(string) ($file['filename'] ?? ''), (string) ($file['path'] ?? ''), (int) ($file['size'] ?? 0), sha1((string) ($file['content'] ?? ''))]);
    }
}
