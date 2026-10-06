import { router } from '@inertiajs/react';
import { ChevronDown, FileOutput } from 'lucide-react';

import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import type { Option } from '@/types';

/** "Gerar em…" menu: posts the chosen format to `action` and lands on the new file. */
export function ExportMenu({
    action,
    formats,
    label = 'Exportar',
    exclude,
}: {
    action: string;
    formats: Option[];
    label?: string;
    exclude?: string;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    <FileOutput />
                    {label}
                    <ChevronDown className="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {formats
                    .filter((f) => f.value !== exclude)
                    .map((f) => (
                        <DropdownMenuItem key={f.value} onSelect={() => router.post(action, { format: f.value })}>
                            {f.label}
                        </DropdownMenuItem>
                    ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
