<?php

namespace App\Ai\Knowledge;

use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use Illuminate\Support\Str;

/**
 * Finds a domain by name or slug and a folder by its path inside it
 * ("Contratos / 2026"), creating missing folders when asked. Used by the
 * agent skills, which speak in names rather than ids.
 */
final class KnowledgeLocator
{
    public function domain(?string $name): ?KnowledgeDomain
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);

        return KnowledgeDomain::query()->get()->first(
            fn (KnowledgeDomain $d) => $d->slug === $slug || Str::slug($d->name) === $slug || mb_strtolower($d->name) === mb_strtolower($name),
        );
    }

    public function folder(KnowledgeDomain $domain, ?string $path, bool $create = false): ?KnowledgeFolder
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('#\s*[/>]\s*#u', (string) $path) ?: []), fn ($p) => $p !== ''));
        $folder = null;

        foreach (array_slice($parts, 0, 5) as $name) {
            $next = KnowledgeFolder::query()
                ->where('knowledge_domain_id', $domain->id)
                ->where('parent_id', $folder?->id)
                ->get()
                ->first(fn (KnowledgeFolder $f) => mb_strtolower($f->name) === mb_strtolower($name));

            if ($next === null) {
                if (! $create) {
                    return null;
                }

                $next = KnowledgeFolder::query()->create(['knowledge_domain_id' => $domain->id, 'parent_id' => $folder?->id, 'name' => Str::limit($name, 120, '')]);
            }

            $folder = $next;
        }

        return $folder;
    }
}
