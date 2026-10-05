import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, FileText, PenLine, RefreshCw, Send, ShieldAlert, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EntityRow, ListPanel, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { CategoryBadge } from '@/Components/CategoryBadge';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { emailTone, type EmailSummary } from '@/Pages/Inbox/Index';
import type { Option } from '@/types';

interface ConversationMessage extends EmailSummary {
    to: string[];
    cc: string[];
    body: string;
    extracted: Record<string, unknown> | null;
    flags: string[];
    department: string | null;
    erp_lead_id: string | null;
    agent_run_id: number | null;
    attachments: { id: number; filename: string; mime_type: string | null; size_bytes: number; downloadable: boolean; ocr_status: string }[];
}

interface Props {
    message: EmailSummary;
    conversation: ConversationMessage[];
    tasks: { id: number; ref: string; title: string }[];
    followUps: { id: number; title: string; due_at: string; done: boolean }[];
    categories: Option[];
}

const priorities: Record<string, { label: string; tone: Tone }> = {
    urgent: { label: 'Urgente', tone: 'danger' },
    high: { label: 'Alta', tone: 'warning' },
    normal: { label: 'Normal', tone: 'idle' },
    low: { label: 'Baixa', tone: 'idle' },
};

const bytes = (size: number) => (size >= 1_048_576 ? `${(size / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(size / 1024))} KB`);

function DraftEditor({ draft }: { draft: ConversationMessage }) {
    const form = useForm({ to: draft.to, subject: draft.subject, body: draft.body });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/inbox/${draft.id}/send`);
    };

    return (
        <form id={`draft-${draft.id}`} onSubmit={submit} className="scroll-mt-20 rounded-xl border border-status-warning/40 bg-card">
            <div className="flex items-center gap-2 border-b px-5 py-3">
                <PenLine className="size-4 text-status-warning" />
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold">Rascunho do agente</p>
                    <p className="truncate text-xs text-muted-foreground">Reveja e edite antes de enviar. Sai da caixa {draft.mailbox}.</p>
                </div>
                <StatusBadge tone="warning">{draft.status_label}</StatusBadge>
            </div>
            <div className="grid gap-4 p-5">
                <Field id={`to-${draft.id}`} label="Para" error={form.errors.to}>
                    <Input
                        id={`to-${draft.id}`}
                        value={form.data.to.join(', ')}
                        onChange={(e) =>
                            form.setData(
                                'to',
                                e.target.value
                                    .split(',')
                                    .map((a) => a.trim())
                                    .filter(Boolean),
                            )
                        }
                    />
                </Field>
                <Field id={`subject-${draft.id}`} label="Assunto" error={form.errors.subject}>
                    <Input id={`subject-${draft.id}`} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                </Field>
                <Field id={`body-${draft.id}`} label="Texto" error={form.errors.body}>
                    <Textarea id={`body-${draft.id}`} rows={10} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                </Field>
            </div>
            <div className="flex items-center justify-between gap-2 border-t px-5 py-3">
                <Button type="button" variant="ghost" onClick={() => confirm('Descartar este rascunho?') && router.delete(`/inbox/${draft.id}`)}>
                    <Trash2 />
                    Descartar
                </Button>
                <Button type="submit" disabled={form.processing}>
                    <Send />
                    Enviar
                </Button>
            </div>
        </form>
    );
}

function MessageBlock({ m, current }: { m: ConversationMessage; current: boolean }) {
    const outbound = m.direction === 'outbound';
    const sender = outbound ? `Enviado por ${m.mailbox}` : (m.from ?? m.from_address ?? '—');

    return (
        <article className={cn('rounded-xl border bg-card', outbound && 'bg-accent/30', current && 'ring-1 ring-primary/20')}>
            <header className="flex items-start gap-3 border-b px-5 py-3">
                <Monogram name={(outbound ? m.mailbox : m.from) ?? '?'} agent={outbound} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="truncate text-sm font-medium">{sender}</span>
                        {!outbound && m.from_address && m.from_address !== m.from && (
                            <span className="truncate text-xs text-muted-foreground">&lt;{m.from_address}&gt;</span>
                        )}
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        Para: {m.to.join(', ')}
                        {m.cc.length > 0 && ` · Cc: ${m.cc.join(', ')}`}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {outbound && <StatusBadge tone={emailTone(m.status)}>{m.status_label}</StatusBadge>}
                    <span className="text-xs whitespace-nowrap text-muted-foreground" title={dateTime(m.date)}>
                        {ago(m.date)}
                    </span>
                </div>
            </header>
            <div className="grid gap-4 px-5 py-4">
                <p className="text-sm leading-relaxed whitespace-pre-wrap">{m.body}</p>
                {m.attachments.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {m.attachments.map((a) =>
                            a.downloadable ? (
                                <a
                                    key={a.id}
                                    href={`/attachments/${a.id}`}
                                    className="inline-flex h-7 items-center gap-1.5 rounded-md border px-2 text-xs transition-colors hover:bg-accent/60"
                                >
                                    <Download className="size-3" />
                                    {a.filename}
                                    <span className="font-mono text-[11px] text-muted-foreground">{bytes(a.size_bytes)}</span>
                                </a>
                            ) : (
                                <span
                                    key={a.id}
                                    className="inline-flex h-7 items-center rounded-md border border-dashed px-2 text-xs text-muted-foreground line-through"
                                    title="Apagado pela retenção ou descartado"
                                >
                                    {a.filename}
                                </span>
                            ),
                        )}
                    </div>
                )}
            </div>
        </article>
    );
}

export default function InboxShow({ message, conversation, tasks, followUps, categories }: Props) {
    const [category, setCategory] = useState(message.category ?? '');
    const current = conversation.find((m) => m.id === message.id) ?? conversation[0];
    const draftsInThread = conversation.filter((m) => m.status === 'draft');
    const priority = current?.priority ? priorities[current.priority] : null;

    return (
        <AppLayout wide breadcrumbs={[{ label: 'Emails', href: '/inbox' }, { label: message.subject }]}>
            <Head title={message.subject} />

            <PageHeader
                title={message.subject}
                description={`${message.mailbox ?? ''} · ${conversation.length} mensagem(ns) na conversa`}
                actions={
                    message.direction === 'inbound' ? (
                        <Button variant="outline" onClick={() => router.post(`/inbox/${message.id}/retriage`)}>
                            <RefreshCw />
                            Triar de novo
                        </Button>
                    ) : undefined
                }
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    {current?.direction === 'inbound' && (current.injection || current.summary) && (
                        <div className="flex flex-col gap-3">
                            {current.injection && (
                                <div className="flex items-start gap-3 rounded-xl border border-status-danger/30 bg-status-danger/8 px-4 py-3 text-sm">
                                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-status-danger" />
                                    <p>
                                        <span className="font-medium text-status-danger">Possível tentativa de manipulação do agente.</span>{' '}
                                        <span className="text-muted-foreground">Leia com cuidado antes de agir sobre este email.</span>
                                    </p>
                                </div>
                            )}
                            {current.summary && (
                                <div className="rounded-xl border bg-card px-5 py-4">
                                    <p className="mb-1 text-xs font-medium tracking-widest text-muted-foreground uppercase">Resumo do agente</p>
                                    <p className="text-sm leading-relaxed">{current.summary}</p>
                                </div>
                            )}
                        </div>
                    )}

                    <Section title="Conversa">
                        <div className="flex flex-col gap-4">
                            {conversation.map((m) =>
                                m.status === 'draft' ? (
                                    <DraftEditor key={m.id} draft={m} />
                                ) : (
                                    <MessageBlock key={m.id} m={m} current={m.id === message.id} />
                                ),
                            )}
                        </div>
                    </Section>
                </div>

                <div className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
                    {current?.direction === 'inbound' && (
                        <Properties title="Triagem">
                            <Property label="Categoria">
                                <CategoryBadge category={current.category} label={current.category_label} />
                            </Property>
                            <Property label="Prioridade">
                                {priority ? (
                                    <StatusBadge tone={priority.tone} dot={priority.tone !== 'idle'}>
                                        {priority.label}
                                    </StatusBadge>
                                ) : (
                                    current.priority
                                )}
                            </Property>
                            <Property label="Estado">
                                <StatusBadge tone={emailTone(current.status)}>{current.status_label}</StatusBadge>
                            </Property>
                            <Property label="Tarefa">
                                {tasks.map((task) => (
                                    <Link key={task.id} href={`/tasks/${task.id}`} className="block truncate hover:underline" title={task.title}>
                                        <span className="font-mono">{task.ref}</span> {task.title}
                                    </Link>
                                ))}
                            </Property>
                            <Property label="Encaminhado a">{current.routed_to}</Property>
                            <Property label="Departamento">{current.department}</Property>
                            <Property label="Prazo">
                                {current.deadline_at && (
                                    <span className="tabular-nums" title={dateTime(current.deadline_at)}>
                                        {dateTime(current.deadline_at)}
                                    </span>
                                )}
                            </Property>
                            <Property label="Lead no ERP">
                                {current.erp_lead_id && <span className="font-mono text-xs">{current.erp_lead_id}</span>}
                            </Property>
                            <Property label="Caixa">{current.mailbox}</Property>
                            {current.agent_run_id && (
                                <Property label="Execução">
                                    <Link href={`/runs/${current.agent_run_id}`} className="font-mono text-xs text-primary hover:underline">
                                        #{current.agent_run_id}
                                    </Link>
                                </Property>
                            )}

                            <div className="mt-3 grid gap-2 border-t pt-3">
                                <p className="text-xs text-muted-foreground">Corrigir a categoria</p>
                                <div className="flex gap-2">
                                    <NativeSelect
                                        className="h-8 min-w-0 flex-1 text-sm"
                                        value={category}
                                        onChange={(e) => setCategory(e.target.value)}
                                    >
                                        {categories.map((c) => (
                                            <option key={c.value} value={c.value}>
                                                {c.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!category || category === current.category}
                                        onClick={() => router.put(`/inbox/${message.id}/category`, { category })}
                                    >
                                        Corrigir
                                    </Button>
                                </div>
                            </div>
                        </Properties>
                    )}

                    {draftsInThread.length > 0 && (
                        <div className="flex flex-col gap-2 rounded-xl border border-status-warning/40 bg-status-warning/8 p-4">
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <StatusDot tone="warning" pulse={false} />
                                Resposta por enviar
                            </p>
                            <p className="text-xs text-muted-foreground">
                                O agente preparou {draftsInThread.length === 1 ? 'um rascunho' : `${draftsInThread.length} rascunhos`}. Reveja e
                                envie.
                            </p>
                            {draftsInThread.map((d) => (
                                <Button key={d.id} size="sm" variant="outline" className="justify-start" asChild>
                                    <a href={`#draft-${d.id}`}>
                                        <PenLine />
                                        Rever rascunho
                                    </a>
                                </Button>
                            ))}
                        </div>
                    )}

                    {current?.extracted && Object.keys(current.extracted).length > 0 && (
                        <Properties title="Dados extraídos">
                            {Object.entries(current.extracted).map(([key, value]) => (
                                <Property key={key} label={key}>
                                    <span className="text-xs">{typeof value === 'object' ? JSON.stringify(value) : String(value)}</span>
                                </Property>
                            ))}
                        </Properties>
                    )}

                    {followUps.length > 0 && (
                        <Section title="Seguimentos">
                            <ListPanel>
                                {followUps.map((f) => (
                                    <EntityRow
                                        key={f.id}
                                        leading={<StatusDot tone={f.done ? 'success' : 'warning'} pulse={false} />}
                                        title={<span className={f.done ? 'text-muted-foreground line-through' : ''}>{f.title}</span>}
                                        subtitle={<span title={dateTime(f.due_at)}>{dateTime(f.due_at)}</span>}
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
