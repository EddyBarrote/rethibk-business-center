import { usePage } from '@inertiajs/react';

import { Monogram } from '@/Components/Blocks';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * A person's face: their photo when they have one, their initials otherwise
 * (docs/DECISOES.md, "Foto de perfil"). Like AgentAvatar, without a url the
 * photo is found by name among the colleagues who have one (shared with every
 * page), so a screen that only knows the name still shows the face.
 */
export function PersonAvatar({ name, url, className }: { name: string; url?: string | null; className?: string }) {
    const photos = usePage<SharedProps>().props.people_photos ?? {};
    const src = url === undefined ? photos[name] : url;

    if (!src) {
        return <Monogram name={name} className={className} />;
    }

    return <img src={src} alt="" className={cn('inline-block size-7 shrink-0 rounded-lg bg-muted object-cover', className)} />;
}
