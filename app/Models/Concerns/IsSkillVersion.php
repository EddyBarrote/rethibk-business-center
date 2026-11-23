<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;
use LogicException;

/**
 * Shared by SkillVersion (a company's skill) and PlatformSkillVersion (a
 * global one): a numbered, immutable copy of what agents read in the skill
 * (docs/DECISOES.md, "Latência, taxa de erro e versões das skills").
 * Rows are written and read, never changed or removed; they go only with
 * their skill.
 *
 * @property int $id
 * @property int $number
 * @property string $key
 * @property string|null $name
 * @property string|null $description
 * @property string|null $instructions
 * @property list<array{filename: string, path: string, mime: string|null, size: int, content: string|null}>|null $files
 * @property int|null $restored_from
 * @property Carbon|null $created_at
 */
trait IsSkillVersion
{
    public static function bootIsSkillVersion(): void
    {
        static::updating(fn () => throw new LogicException('Skill versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Skill versions are immutable.'));
    }

    public function initializeIsSkillVersion(): void
    {
        $this->mergeCasts(['files' => 'array', 'number' => 'integer', 'restored_from' => 'integer', 'created_at' => 'datetime']);
    }

    /**
     * What agents read, as SkillVersions compares and restores it.
     *
     * @return array{name: string, description: string, instructions: string, files: list<array{filename: string, path: string, mime: string|null, size: int, content: string|null}>}
     */
    public function content(): array
    {
        return [
            'name' => (string) $this->name,
            'description' => (string) $this->description,
            'instructions' => (string) $this->instructions,
            'files' => $this->files ?? [],
        ];
    }
}
