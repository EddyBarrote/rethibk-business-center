import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, BookOpen, CircleSlash, FileText } from 'lucide-react';
import { type FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { bytes } from '@/lib/format';

interface ImportedSkill {
    index: number;
    source: string;
    folder: string;
    key: string;
    key_taken: boolean;
    name: string;
    description: string;
    instructions: string;
    files: { filename: string; size: number }[];
    left_out: { filename: string; size: number; reason: string }[];
    notes: string[];
}

interface Choice {
    index: number;
    include: boolean;
    key: string;
    name: string;
    description: string;
}

/**
 * What an upload of skills brings in, before anything is saved: each skill
 * with the files that come with it and the ones left out, and why.
 */
export default function SkillImport({ token, skills, problems }: { token: string; skills: ImportedSkill[]; problems: { source: string; reason: string }[] }) {
    const form = useForm<{ choices: Choice[] }>({
        choices: skills.map((skill) => ({ index: skill.index, include: true, key: skill.key, name: skill.name, description: skill.description })),
    });
    const chosen = form.data.choices.filter((choice) => choice.include);
    const errors = form.errors as Record<string, string>;

    const set = (position: number, patch: Partial<Choice>) =>
        form.setData(
            'choices',
            form.data.choices.map((choice, i) => (i === position ? { ...choice, ...patch } : choice)),
        );

    // Errors come back by position among the chosen ones.
    const errorFor = (position: number, field: string) => {
        const at = chosen.indexOf(form.data.choices[position]);
        return at === -1 ? undefined : errors[`skills.${at}.${field}`];
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ skills: data.choices.filter((c) => c.include).map(({ include: _include, ...rest }) => rest) }));
        form.post(`/settings/skills/import/${token}`);
    };

    return (
        <SettingsLayout crumbs={[{ label: 'Importar skills' }]}>
            <Head title="Importar skills" />
            <PageHeader
                title="Importar skills"
                description="Confira o que entra antes de guardar. Pode mudar o nome, a chave e o «quando usar»; as instruções e os ficheiros editam-se depois na skill."
            />

            <form onSubmit={submit} className="flex flex-col gap-6">
                {problems.length > 0 && (
                    <div className="rounded-xl border border-status-danger/30 bg-status-danger/5 p-4">
                        <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                            <AlertTriangle className="size-4 text-status-danger" />
                            Não deu para ler
                        </h2>
                        <ul className="grid gap-1 text-sm">
                            {problems.map((problem, i) => (
                                <li key={i}>
                                    <span className="font-medium">{problem.source}</span>: {problem.reason}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {skills.length === 0 && (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">Nenhuma skill encontrada nos ficheiros enviados.</p>
                )}

                {skills.map((skill, position) => {
                    const choice = form.data.choices[position];

                    return (
                        <section key={skill.index} className="rounded-xl border bg-card">
                            <header className="flex items-start gap-3 border-b px-4 py-3">
                                <Checkbox
                                    id={`include-${skill.index}`}
                                    className="mt-1"
                                    checked={choice.include}
                                    onCheckedChange={(on) => set(position, { include: on === true })}
                                    aria-label="Importar esta skill"
                                />
                                <div className="min-w-0 flex-1">
                                    <label htmlFor={`include-${skill.index}`} className="flex items-center gap-2 font-medium">
                                        <BookOpen className="size-4 text-muted-foreground" />
                                        {choice.name || skill.folder}
                                    </label>
                                    <p className="font-mono text-xs text-muted-foreground">
                                        {skill.source}
                                        {skill.folder && skill.folder !== skill.source.replace(/\.[^.]+$/, '') ? ` › ${skill.folder}` : ''}
                                    </p>
                                </div>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {skill.files.length} ficheiro(s) · {skill.left_out.length} de fora
                                </span>
                            </header>

                            {choice.include && (
                                <div className="grid gap-5 p-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                                    <div className="grid min-w-0 gap-4">
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field id={`name-${skill.index}`} label="Nome" error={errorFor(position, 'name')}>
                                                <Input id={`name-${skill.index}`} value={choice.name} onChange={(e) => set(position, { name: e.target.value })} />
                                            </Field>
                                            <Field
                                                id={`key-${skill.index}`}
                                                label="Chave"
                                                error={errorFor(position, 'key')}
                                                hint={skill.key_taken ? 'Já havia uma skill com esta chave; ficou com um número.' : 'Letras, números e hífen.'}
                                            >
                                                <Input
                                                    id={`key-${skill.index}`}
                                                    className="font-mono"
                                                    value={choice.key}
                                                    onChange={(e) => set(position, { key: e.target.value })}
                                                />
                                            </Field>
                                        </div>
                                        <Field id={`description-${skill.index}`} label="Quando usar" error={errorFor(position, 'description')}>
                                            <Textarea
                                                id={`description-${skill.index}`}
                                                rows={3}
                                                value={choice.description}
                                                onChange={(e) => set(position, { description: e.target.value })}
                                            />
                                        </Field>
                                        <Collapsible>
                                            <CollapsibleTrigger asChild>
                                                <Button type="button" variant="ghost" size="sm" className="-ml-2">
                                                    Ver as instruções ({skill.instructions.length.toLocaleString('pt-PT')} caracteres)
                                                </Button>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="mt-2 max-h-96 overflow-y-auto rounded-lg border p-4">
                                                <Markdown>{skill.instructions}</Markdown>
                                            </CollapsibleContent>
                                        </Collapsible>
                                        {skill.notes.length > 0 && (
                                            <ul className="grid gap-1.5">
                                                {skill.notes.map((note, i) => (
                                                    <li key={i} className="flex gap-2 text-sm text-muted-foreground">
                                                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" />
                                                        {note}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>

                                    <aside className="flex flex-col gap-4">
                                        <div>
                                            <h3 className="mb-2 text-sm font-semibold">Entram</h3>
                                            {skill.files.length === 0 ? (
                                                <p className="text-sm text-muted-foreground">Só as instruções.</p>
                                            ) : (
                                                <ul className="grid gap-1.5">
                                                    {skill.files.map((file) => (
                                                        <li key={file.filename} className="flex items-center gap-2 text-sm">
                                                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                                                            <span className="min-w-0 flex-1 truncate">{file.filename}</span>
                                                            <span className="font-mono text-[11px] text-muted-foreground">{bytes(file.size)}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                        {skill.left_out.length > 0 && (
                                            <div>
                                                <h3 className="mb-2 text-sm font-semibold">Ficam de fora</h3>
                                                <ul className="grid gap-2">
                                                    {skill.left_out.map((file) => (
                                                        <li key={file.filename} className="flex gap-2 text-sm">
                                                            <CircleSlash className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                                            <span className="min-w-0 flex-1">
                                                                <span className="block truncate">{file.filename}</span>
                                                                <span className="block text-xs text-muted-foreground">{file.reason}</span>
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                    </aside>
                                </div>
                            )}
                        </section>
                    );
                })}

                {errors.skills && <p className="text-sm text-destructive">{errors.skills}</p>}

                <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-between gap-3 border-t bg-background/85 px-1 py-3 backdrop-blur">
                    <Button type="button" variant="ghost" onClick={() => router.delete(`/settings/skills/import/${token}`)}>
                        Cancelar
                    </Button>
                    <div className="flex items-center gap-3">
                        <StatusBadge tone="idle" dot={false}>
                            sem agentes atribuídos
                        </StatusBadge>
                        <Button type="submit" disabled={form.processing || chosen.length === 0}>
                            {chosen.length === 1 ? 'Importar 1 skill' : `Importar ${chosen.length} skills`}
                        </Button>
                    </div>
                </div>
            </form>

        </SettingsLayout>
    );
}
