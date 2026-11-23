<?php

namespace App\Ai\Skills;

use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Support\TextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to a skill: kept on the private disk, with their text
 * extracted once so agents read them with skills.read_file.
 */
final class SkillFiles
{
    public const DISK = 'local';

    /** Accepted in the upload forms. */
    public const RULES = ['file', 'max:10240', 'mimes:md,txt,csv,json,xml,html,pdf,docx,xlsx,css,png,jpg,jpeg,gif,webp,svg'];

    public function __construct(private readonly TextExtractor $extractor) {}

    /**
     * @param  string|null  $filename  the name to keep, when it is a path inside an imported skill ("assets/logo.png")
     */
    public function attach(Skill|PlatformSkill $skill, UploadedFile $file, ?string $filename = null): SkillFile|PlatformSkillFile
    {
        $filename = Str::limit($filename ?? $file->getClientOriginalName(), 200, '');
        $path = $file->storeAs($this->folder($skill), Str::random(8).'-'.Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.'.Str::lower(pathinfo($filename, PATHINFO_EXTENSION)), self::DISK);

        $attributes = [
            'filename' => $filename,
            'path' => (string) $path,
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'content' => $this->extractor->extract($file->getRealPath(), $file->getClientMimeType(), $filename),
        ];

        // A file with the same name replaces the previous version.
        $existing = $skill->files()->where('filename', $filename)->first();

        if ($existing !== null) {
            $this->detach($existing);
        }

        return $skill->files()->create($attributes);
    }

    /**
     * The file leaves the skill. Its stored copy stays: the skill's earlier
     * versions point at it, and restoring one brings it back (SkillVersions).
     */
    public function detach(SkillFile|PlatformSkillFile $file): void
    {
        $file->delete();
    }

    /**
     * The skill is deleted: every stored file of it goes, those of its
     * earlier versions too.
     */
    public function purge(Skill|PlatformSkill $skill): void
    {
        $skill->files()->reorder()->delete();
        Storage::disk(self::DISK)->deleteDirectory($this->folder($skill));
    }

    private function folder(Skill|PlatformSkill $skill): string
    {
        return $skill instanceof PlatformSkill ? "skills/platform/{$skill->id}" : "skills/{$skill->tenant_id}/{$skill->id}";
    }
}
