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
    public const RULES = ['file', 'max:10240', 'mimes:md,txt,csv,json,xml,html,pdf,docx,xlsx'];

    public function __construct(private readonly TextExtractor $extractor) {}

    public function attach(Skill|PlatformSkill $skill, UploadedFile $file): SkillFile|PlatformSkillFile
    {
        $filename = Str::limit($file->getClientOriginalName(), 200, '');
        $folder = $skill instanceof PlatformSkill ? "skills/platform/{$skill->id}" : "skills/{$skill->tenant_id}/{$skill->id}";
        $path = $file->storeAs($folder, Str::random(8).'-'.Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension(), self::DISK);

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

    public function detach(SkillFile|PlatformSkillFile $file): void
    {
        Storage::disk(self::DISK)->delete($file->path);
        $file->delete();
    }
}
