import { useForm } from '@inertiajs/react';
import { FileUp, Upload, X } from 'lucide-react';
import { type DragEvent, useRef, useState } from 'react';

import { FormDialog } from '@/Components/Dialogs';
import { Button } from '@/Components/ui/button';
import { bytes } from '@/lib/format';
import { cn } from '@/lib/utils';

const ACCEPT = '.md,.markdown,.txt,.zip,.skill';

/**
 * Upload skills made for Claude, Codex and similar tools. Nothing is saved
 * here: the next page shows what came in and what was left out.
 */
export function SkillImportDialog() {
    const [open, setOpen] = useState(false);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ files: File[] }>({ files: [] });

    const add = (list: FileList | null) => {
        if (list && list.length > 0) {
            form.setData('files', [...form.data.files, ...Array.from(list)].slice(0, 20));
        }
    };

    const drop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        add(event.dataTransfer.files);
    };

    const errors = Object.entries(form.errors as Record<string, string>);

    return (
        <FormDialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            trigger={
                <Button variant="outline">
                    <Upload />
                    Importar
                </Button>
            }
            title="Importar skills"
            description="Skills feitas para o Claude, o Codex ou ferramentas parecidas: um SKILL.md, ou a pasta da skill em .zip ou .skill. Um .zip pode trazer várias."
            submitLabel="Ver o que entra"
            processing={form.processing}
            disabled={form.data.files.length === 0}
            onSubmit={() => form.post('/settings/skills/import', { forceFormData: true })}
        >
            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={drop}
                className={cn(
                    'flex flex-col items-center gap-2 rounded-lg border border-dashed border-input px-4 py-8 text-center transition-colors',
                    dragging && 'border-primary bg-primary/5',
                )}
            >
                <input ref={input} type="file" multiple accept={ACCEPT} className="sr-only" onChange={(e) => add(e.target.files)} />
                <FileUp className="size-6 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Arraste os ficheiros para aqui ou escolha do computador.</p>
                <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
                    Escolher ficheiros
                </Button>
            </div>

            {form.data.files.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {form.data.files.map((file, i) => (
                        <li key={`${file.name}-${i}`} className="flex items-center gap-2 px-3 py-2">
                            <span className="min-w-0 flex-1 truncate text-sm">{file.name}</span>
                            <span className="font-mono text-xs text-muted-foreground">{bytes(file.size)}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Retirar"
                                onClick={() => form.setData('files', form.data.files.filter((_, j) => j !== i))}
                            >
                                <X />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Antes de guardar, vê o que entra e o que fica de fora. Scripts nunca entram nem são executados, e a skill não fica atribuída a nenhum
                agente até escolher quem a usa.
            </p>

            {errors.length > 0 && (
                <ul className="text-sm text-destructive">
                    {errors.map(([key, message]) => (
                        <li key={key}>{message}</li>
                    ))}
                </ul>
            )}
        </FormDialog>
    );
}
