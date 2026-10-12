import { Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Lock, Pencil, Trash2, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { ConfirmDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { Markdown } from '@/Components/Markdown';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { ago, date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * What an agent remembers, as named entries in sections, like claude.ai
 * (docs/DECISOES.md, "Memória em entradas", 12.10.2026). Used by the person's
 * own page (A minha conta › Memória) and by the administrators on the agent's page.
 */

export interface MemoryEntry {
    id: number;
    section: string;
    title: string;
    description: string | null;
    content: string;
    kind: 'work' | 'personal';
    agent_id: number;
    person: { id: number; name: string } | null;
    about: string | null;
    task: { id: number; ref: string } | null;
    edited: boolean;
    updated_at: string;
}

export interface MemorySectionOption {
    value: string;
    label: string;
    hint: string;
    about_you: boolean;
}

/** Sobre si first (Preferências, Perfil), then one group per other section, empty ones left out. */
export function MemoryEntries({
    entries,
    sections,
    url,
    showPerson = false,
}: {
    entries: MemoryEntry[];
    sections: MemorySectionOption[];
    /** Where an entry is changed (PUT) and deleted (DELETE). */
    url: (entry: MemoryEntry) => string;
    showPerson?: boolean;
}) {
    const [open, setOpen] = useState<MemoryEntry | null>(null);
    const about = sections.filter((s) => s.about_you);
    const groups = [
        { key: 'about', label: 'Sobre si', hint: 'Vai sempre inteiro para o agente.', sections: about },
        ...sections.filter((s) => !s.about_you).map((s) => ({ key: s.value, label: s.label, hint: s.hint, sections: [s] })),
    ]
        .map((group) => ({ ...group, entries: entries.filter((e) => group.sections.some((s) => s.value === e.section)) }))
        .filter((group) => group.entries.length > 0);
    const current = open ? (entries.find((e) => e.id === open.id) ?? null) : null;

    return (
        <div className="flex flex-col gap-6">
            {groups.map((group) => (
                <section key={group.key} className="flex flex-col gap-2">
                    <div className="flex items-baseline justify-between gap-3 px-1">
                        <h3 className="text-[11px] font-medium tracking-widest text-muted-foreground uppercase">{group.label}</h3>
                        <span className="hidden truncate text-xs text-muted-foreground/80 sm:block">{group.hint}</span>
                    </div>
                    <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                        {group.entries.map((entry) => (
                            <li key={entry.id}>
                                <button
                                    type="button"
                                    onClick={() => setOpen(entry)}
                                    className="group flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate text-sm font-medium">{entry.title}</span>
                                            {group.key === 'about' && entry.title !== sections.find((s) => s.value === entry.section)?.label && (
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {sections.find((s) => s.value === entry.section)?.label}
                                                </span>
                                            )}
                                        </div>
                                        {entry.description && <p className="truncate text-sm text-muted-foreground">{entry.description}</p>}
                                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground/80">
                                            {showPerson && (
                                                <span className="font-medium text-foreground/80">{entry.person?.name ?? 'Do agente'}</span>
                                            )}
                                            <span title={dateTime(entry.updated_at)}>Actualizada {date(entry.updated_at)}</span>
                                            {entry.kind === 'work' && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Users className="size-3" />
                                                    partilhada
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
            <EntrySheet entry={current} sections={sections} url={url} showPerson={showPerson} onClose={() => setOpen(null)} />
        </div>
    );
}

function EntrySheet({
    entry,
    sections,
    url,
    showPerson,
    onClose,
}: {
    entry: MemoryEntry | null;
    sections: MemorySectionOption[];
    url: (entry: MemoryEntry) => string;
    showPerson: boolean;
    onClose: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [forgetting, setForgetting] = useState(false);
    const form = useForm({ section: '', title: '', description: '', content: '', kind: 'work' as MemoryEntry['kind'] });
    const section = sections.find((s) => s.value === (editing ? form.data.section : entry?.section));

    const edit = (e: MemoryEntry) => {
        form.setData({ section: e.section, title: e.title, description: e.description ?? '', content: e.content, kind: e.kind });
        form.clearErrors();
        setEditing(true);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (entry) {
            form.put(url(entry), { preserveScroll: true, onSuccess: () => setEditing(false) });
        }
    };

    return (
        <Sheet
            open={entry !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setEditing(false);
                    onClose();
                }
            }}
        >
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
                {entry && (
                    <>
                        <SheetHeader className="border-b px-6 pt-6 pb-4">
                            <p className="text-xs font-medium text-muted-foreground">
                                {section?.about_you ? 'Sobre si · ' : ''}
                                {section?.label}
                            </p>
                            <SheetTitle className="pr-6 text-lg">{editing ? 'Corrigir entrada' : entry.title}</SheetTitle>
                            {!editing && entry.description && <SheetDescription>{entry.description}</SheetDescription>}
                        </SheetHeader>

                        {editing ? (
                            <form id="memory-entry" onSubmit={save} className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                                <Field id="memory-section" label="Secção" error={form.errors.section} hint={section?.hint}>
                                    <NativeSelect
                                        id="memory-section"
                                        value={form.data.section}
                                        onChange={(e) => form.setData('section', e.target.value)}
                                    >
                                        {sections.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.about_you ? `Sobre si · ${s.label}` : s.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="memory-title" label="Título" error={form.errors.title}>
                                    <Input
                                        id="memory-title"
                                        value={form.data.title}
                                        maxLength={120}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                    />
                                </Field>
                                <Field
                                    id="memory-description"
                                    label="Descrição"
                                    error={form.errors.description}
                                    hint="Uma linha: é o que o agente vê no índice."
                                >
                                    <Input
                                        id="memory-description"
                                        value={form.data.description}
                                        maxLength={200}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                    />
                                </Field>
                                <Field id="memory-content" label="Conteúdo" error={form.errors.content}>
                                    <Textarea
                                        id="memory-content"
                                        rows={12}
                                        value={form.data.content}
                                        maxLength={4000}
                                        onChange={(e) => form.setData('content', e.target.value)}
                                        className="min-h-56 font-mono text-[13px]"
                                    />
                                </Field>
                                <label htmlFor="memory-kind" className="flex items-start justify-between gap-4 rounded-lg border p-3">
                                    <span className="space-y-0.5">
                                        <span className="block text-sm font-medium">Partilhar com os colegas</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {section?.about_you
                                                ? 'O que é sobre si fica sempre entre si e o agente.'
                                                : 'Vai também para a base de conhecimento (Memória dos agentes), onde serve os outros.'}
                                        </span>
                                    </span>
                                    <Switch
                                        id="memory-kind"
                                        checked={form.data.kind === 'work' && !section?.about_you}
                                        disabled={section?.about_you}
                                        onCheckedChange={(on) => form.setData('kind', on ? 'work' : 'personal')}
                                    />
                                </label>
                            </form>
                        ) : (
                            <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                                <div className="prose-sm text-sm">
                                    <Markdown>{entry.content}</Markdown>
                                </div>
                                <dl className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 border-t pt-4 text-xs">
                                    {showPerson && (
                                        <>
                                            <dt className="text-muted-foreground">Memória de</dt>
                                            <dd>{entry.person?.name ?? 'Do agente (sem pessoa)'}</dd>
                                        </>
                                    )}
                                    <dt className="text-muted-foreground">Visível</dt>
                                    <dd>
                                        {entry.kind === 'work' ? (
                                            <StatusBadge tone="running" dot={false}>
                                                <Users className="size-3" /> também na base de conhecimento
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge tone="idle" dot={false}>
                                                <Lock className="size-3" /> só nesta memória
                                            </StatusBadge>
                                        )}
                                    </dd>
                                    {entry.about && (
                                        <>
                                            <dt className="text-muted-foreground">Pessoa</dt>
                                            <dd>{entry.about}</dd>
                                        </>
                                    )}
                                    <dt className="text-muted-foreground">Actualizada</dt>
                                    <dd title={dateTime(entry.updated_at)}>
                                        {ago(entry.updated_at)}
                                        {entry.edited && ' · corrigida à mão'}
                                    </dd>
                                    {entry.task && (
                                        <>
                                            <dt className="text-muted-foreground">De onde veio</dt>
                                            <dd>
                                                <Link href={`/tasks/${entry.task.id}`} className="hover:underline">
                                                    {entry.task.ref}
                                                </Link>
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </div>
                        )}

                        <SheetFooter className={cn('flex-row items-center justify-between gap-2 border-t px-6 py-4')}>
                            {editing ? (
                                <>
                                    <Button key="cancel" type="button" variant="ghost" onClick={() => setEditing(false)} disabled={form.processing}>
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        form="memory-entry"
                                        disabled={form.processing || form.data.title.trim() === '' || form.data.content.trim() === ''}
                                    >
                                        Guardar
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <Button
                                        key="forget"
                                        type="button"
                                        variant="ghost"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => setForgetting(true)}
                                    >
                                        <Trash2 />
                                        Esquecer
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => edit(entry)}>
                                        <Pencil />
                                        Corrigir
                                    </Button>
                                </>
                            )}
                        </SheetFooter>

                        <ConfirmDialog
                            open={forgetting}
                            onOpenChange={setForgetting}
                            title="Esquecer esta entrada?"
                            description={`«${entry.title}» deixa de estar na memória do agente${entry.kind === 'work' ? ' e na base de conhecimento' : ''}.`}
                            confirmLabel="Esquecer"
                            destructive
                            onConfirm={() =>
                                router.delete(url(entry), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setForgetting(false);
                                        onClose();
                                    },
                                    onFinish: () => setForgetting(false),
                                })
                            }
                        />
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
