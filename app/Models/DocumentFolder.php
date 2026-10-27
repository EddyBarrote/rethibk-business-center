<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\DocumentFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of Ficheiros (docs/DECISOES.md, "Gestor de ficheiros"), shared by
 * the company. Who sees a file still follows the file, not the folder.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int|null $created_by_user_id
 */
#[Fillable(['name', 'created_by_user_id'])]
class DocumentFolder extends Model
{
    /** @use HasFactory<DocumentFolderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }
}
