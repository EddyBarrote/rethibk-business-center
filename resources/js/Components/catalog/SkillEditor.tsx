import { Link, router, useForm } from '@inertiajs/react';
import { FileText, Trash2, Upload } from 'lucide-react';
import { type FormEvent, useRef } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { FormSection } from '@/Components/agents/FormParts';
import { ConfirmDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { Markdown } from '@/Components/Markdown';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import { bytes } from '@/lib/format';

export interface SkillData {
    id: number;
    key: string;
    name: string;
    description: string;
    instructions: string;
    is_enabled?: boolean;
    is_active?: boolean;
    agents?: { id: number; name: string; avatar_url?: string | null }[];
}

export interface SkillFileRow {
    id: number;
    filename: string;
    size: number;
    has_text: boolean;
}

const starter = `# Quando usar
Descreva a situação a que esta skill se aplica.

# Como fazer
1. Primeiro passo.
2. Segundo passo.

# Nunca
- O que o agente nunca deve fazer neste trabalho.
`;


/**
 * A skill in the Claude sense: name, when it applies, markdown instructions
 * and attached files. Same editor for the company's skills and the global ones.
 */
export function SkillEditor({
    base,
    skill,
    files,
    cancelHref,
    switchField,
}: {
    base: string;
    skill: SkillData | null;
    files: SkillFileRow[];
    cancelHref: string;
    /** "is_enabled" for a company's skill, "is_active" for a global one. */
    switchField: 'is_enabled' | 'is_active';
}) {
    const form = useForm({
        key: skill?.key ?? '',
        name: skill?.name ?? '',
        description: skill?.description ?? '',
        instructions: skill?.instructions ?? starter,
        [switchField]: skill ? (skill[switchField] ?? true) : true,
    } as { key: string; name: string; description: string; instructions: string } & Record<string, boolean | string>);
    const upload = useForm<{ file: File | null }>({ file: null });
    const fileInput = useRef<HTMLInputElement>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (skill) {
            form.put(`${base}/${skill.id}`, { preserveScroll: true });
        } else {
            form.post(base);
        }
    };

    const send = (file: File | undefined) => {
        if (!file || !skill) {
            return;
        }

        upload.setData('file', file);
        upload.transform(() => ({ file }));
        upload.post(`${base}/${skill.id}/files`, { preserveScroll: true, forceFormData: true, onFinish: () => fileInput.current && (fileInput.current.value = '') });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <form onSubmit={submit} className="flex min-w-0 flex-col gap-6">
                <FormSection id="skill" title="Skill" description="O agente vê o nome e o «quando usar»; lê as instruções só quando a skill se aplica.">
                    <div className="grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="name" label="Nome" error={form.errors.name}>
                                <Input id="name" placeholder="Propostas comerciais" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </Field>
                            <Field id="key" label="Chave" error={form.errors.key} hint="Letras, números e hífen.">
                                <Input id="key" className="font-mono" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} required />
                            </Field>
                        </div>
                        <Field id="description" label="Quando usar" error={form.errors.description} hint="Uma ou duas frases. É por aqui que o agente decide.">
                            <Textarea
                                id="description"
                                rows={2}
                                placeholder="Usar sempre que for preciso escrever ou rever uma proposta comercial para um cliente."
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                required
                            />
                        </Field>
                        <Tabs defaultValue="edit">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Instruções</span>
                                <TabsList>
                                    <TabsTrigger value="edit">Editar</TabsTrigger>
                                    <TabsTrigger value="preview">Ver</TabsTrigger>
                                </TabsList>
                            </div>
                            <TabsContent value="edit">
                                <Textarea
                                    id="instructions"
                                    rows={20}
                                    className="font-mono text-xs leading-relaxed"
                                    value={form.data.instructions}
                                    onChange={(e) => form.setData('instructions', e.target.value)}
                                    required
                                />
                                {form.errors.instructions && <p className="mt-1 text-sm text-destructive">{form.errors.instructions}</p>}
                            </TabsContent>
                            <TabsContent value="preview" className="min-h-40 rounded-lg border p-4">
                                <Markdown>{form.data.instructions}</Markdown>
                            </TabsContent>
                        </Tabs>
                        <label className="flex items-center gap-3">
                            <Switch checked={Boolean(form.data[switchField])} onCheckedChange={(on) => form.setData(switchField, on)} />
                            <span className="text-sm">{switchField === 'is_active' ? 'Oferecida às organizações' : 'Activa'}</span>
                        </label>
                    </div>
                </FormSection>

                <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-between gap-3 border-t bg-background/85 px-1 py-3 backdrop-blur">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" asChild>
                            <Link href={cancelHref}>Voltar</Link>
                        </Button>
                        {skill && (
                            <ConfirmDialog
                                title={`Apagar a skill ${skill.name}?`}
                                description="Os agentes que a usam deixam de a ter, e os ficheiros dela também saem."
                                confirmLabel="Apagar skill"
                                destructive
                                onConfirm={() => router.delete(`${base}/${skill.id}`)}
                                trigger={
                                    <Button type="button" variant="ghost" className="text-muted-foreground hover:text-status-danger">
                                        <Trash2 />
                                        Apagar
                                    </Button>
                                }
                            />
                        )}
                    </div>
                    <div className="flex items-center gap-3">
                        {form.isDirty && <span className="hidden text-xs text-muted-foreground sm:inline">Há alterações por guardar.</span>}
                        <Button type="submit" disabled={form.processing}>
                            {skill ? 'Guardar skill' : 'Criar skill'}
                        </Button>
                    </div>
                </div>
            </form>

            <aside className="flex flex-col gap-4">
                <div className="rounded-xl border bg-card p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold">Ficheiros</h2>
                        {skill && (
                            <>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    className="hidden"
                                    accept=".md,.txt,.csv,.json,.xml,.html,.pdf,.docx,.xlsx"
                                    onChange={(e) => send(e.target.files?.[0])}
                                />
                                <Button size="sm" variant="outline" disabled={upload.processing} onClick={() => fileInput.current?.click()}>
                                    <Upload />
                                    Juntar
                                </Button>
                            </>
                        )}
                    </div>
                    {!skill ? (
                        <p className="text-sm text-muted-foreground">Crie a skill primeiro; depois pode juntar modelos, exemplos e tabelas.</p>
                    ) : files.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Modelos, exemplos ou tabelas que o agente lê quando usa a skill (md, txt, csv, pdf, docx, xlsx).</p>
                    ) : (
                        <ul className="divide-y">
                            {files.map((file) => (
                                <li key={file.id} className="flex items-center gap-2 py-2">
                                    <FileText className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm">{file.filename}</span>
                                        <span className="font-mono text-[11px] text-muted-foreground">{bytes(file.size)}</span>
                                        {!file.has_text && (
                                            <StatusBadge tone="warning" dot={false} className="ml-2">
                                                sem texto
                                            </StatusBadge>
                                        )}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remover"
                                        className="hover:text-status-danger"
                                        onClick={() => router.delete(`${base}/${skill.id}/files/${file.id}`, { preserveScroll: true })}
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {upload.errors.file && <p className="mt-2 text-sm text-destructive">{upload.errors.file}</p>}
                </div>

                {skill?.agents && (
                    <div className="rounded-xl border bg-card p-4">
                        <h2 className="mb-3 text-sm font-semibold">Agentes com esta skill</h2>
                        {skill.agents.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nenhum ainda. Atribua-a na página de edição de cada agente.</p>
                        ) : (
                            <ul className="grid gap-2">
                                {skill.agents.map((agent) => (
                                    <li key={agent.id}>
                                        <Link href={`/agents/${agent.id}/edit#skills`} className="flex items-center gap-2 text-sm hover:underline">
                                            <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-6 text-[10px]" />
                                            {agent.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </aside>
        </div>
    );
}
