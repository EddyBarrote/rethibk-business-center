import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Download, RefreshCw, Send, ShieldAlert, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { CategoryBadge } from '@/Components/CategoryBadge';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { date, dateTime } from '@/lib/format';
import type { EmailSummary } from '@/Pages/Inbox/Index';
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
    tenders: { id: number; title: string; deadline_at: string | null; status_label: string }[];
    followUps: { id: number; title: string; due_at: string; done: boolean }[];
    categories: Option[];
}

function DraftEditor({ draft }: { draft: ConversationMessage }) {
    const form = useForm({ to: draft.to, subject: draft.subject, body: draft.body });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/inbox/${draft.id}/send`);
    };

    return (
        <Card className="border-amber-300">
            <form onSubmit={submit}>
                <CardHeader>
                    <CardTitle>Rascunho do agente</CardTitle>
                    <CardDescription>Reveja e edite antes de enviar. Sai da caixa {draft.mailbox}.</CardDescription>
                </CardHeader>
                <CardContent className="mt-4 grid gap-4">
                    <Field id={`to-${draft.id}`} label="Para" error={form.errors.to}>
                        <Input id={`to-${draft.id}`} value={form.data.to.join(', ')} onChange={(e) => form.setData('to', e.target.value.split(',').map((a) => a.trim()).filter(Boolean))} />
                    </Field>
                    <Field id={`subject-${draft.id}`} label="Assunto" error={form.errors.subject}>
                        <Input id={`subject-${draft.id}`} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                    </Field>
                    <Field id={`body-${draft.id}`} label="Texto" error={form.errors.body}>
                        <Textarea id={`body-${draft.id}`} rows={10} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>
                </CardContent>
                <CardFooter className="mt-4 justify-between">
                    <Button type="button" variant="ghost" onClick={() => confirm('Descartar este rascunho?') && router.delete(`/inbox/${draft.id}`)}>
                        <Trash2 />
                        Descartar
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        <Send />
                        Enviar
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}

export default function InboxShow({ message, conversation, tenders, followUps, categories }: Props) {
    const [category, setCategory] = useState(message.category ?? '');
    const current = conversation.find((m) => m.id === message.id) ?? conversation[0];

    return (
        <AppLayout>
            <Head title={message.subject} />
            <div>
                <Link href="/inbox" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Caixa
                </Link>
            </div>

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

            {current?.direction === 'inbound' && (
                <Card>
                    <CardContent className="grid gap-4 sm:grid-cols-[2fr_1fr]">
                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <CategoryBadge category={current.category} label={current.category_label} />
                                {current.priority && <Badge variant="outline">prioridade {current.priority}</Badge>}
                                {current.injection && (
                                    <Badge variant="destructive">
                                        <ShieldAlert />
                                        possível tentativa de manipulação do agente
                                    </Badge>
                                )}
                            </div>
                            {current.summary && <p className="text-sm">{current.summary}</p>}
                            {current.extracted && Object.keys(current.extracted).length > 0 && (
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    {Object.entries(current.extracted).map(([key, value]) => (
                                        <div key={key} className="contents">
                                            <dt className="text-muted-foreground">{key}</dt>
                                            <dd>{typeof value === 'object' ? JSON.stringify(value) : String(value)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </div>
                        <div className="grid content-start gap-2 text-sm">
                            {current.routed_to && <p>Encaminhado a <strong>{current.routed_to}</strong></p>}
                            {current.department && <p>Departamento: {current.department}</p>}
                            {current.deadline_at && <p>Prazo: {dateTime(current.deadline_at)}</p>}
                            {current.erp_lead_id && <p>Lead no ERP: {current.erp_lead_id}</p>}
                            {current.agent_run_id && (
                                <Link href={`/runs/${current.agent_run_id}`} className="text-primary hover:underline">
                                    Ver o que o agente fez
                                </Link>
                            )}
                            <div className="flex gap-2 pt-2">
                                <NativeSelect value={category} onChange={(e) => setCategory(e.target.value)}>
                                    {categories.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Button size="sm" variant="outline" disabled={!category || category === current.category} onClick={() => router.put(`/inbox/${message.id}/category`, { category })}>
                                    Corrigir
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            )}

            {(tenders.length > 0 || followUps.length > 0) && (
                <div className="grid gap-4 sm:grid-cols-2">
                    {tenders.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Concurso</CardTitle>
                            </CardHeader>
                            <CardContent className="mt-2 grid gap-1 text-sm">
                                {tenders.map((t) => (
                                    <Link key={t.id} href="/tenders" className="hover:underline">
                                        {t.title} · {t.status_label} · prazo {date(t.deadline_at)}
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                    {followUps.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Seguimentos</CardTitle>
                            </CardHeader>
                            <CardContent className="mt-2 grid gap-1 text-sm">
                                {followUps.map((f) => (
                                    <p key={f.id} className={f.done ? 'text-muted-foreground line-through' : ''}>
                                        {dateTime(f.due_at)} · {f.title}
                                    </p>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}

            <div className="grid gap-4">
                {conversation.map((m) =>
                    m.status === 'draft' ? (
                        <DraftEditor key={m.id} draft={m} />
                    ) : (
                        <Card key={m.id} className={m.direction === 'outbound' ? 'border-primary/30 bg-accent/30' : ''}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="text-sm">{m.direction === 'outbound' ? `Enviado por ${m.mailbox}` : m.from}</CardTitle>
                                    <span className="text-xs text-muted-foreground">{dateTime(m.date)}</span>
                                </div>
                                <CardDescription>Para: {m.to.join(', ')}{m.cc.length > 0 && ` · Cc: ${m.cc.join(', ')}`}</CardDescription>
                            </CardHeader>
                            <CardContent className="mt-3 grid gap-3">
                                <p className="text-sm leading-relaxed whitespace-pre-wrap">{m.body}</p>
                                {m.attachments.length > 0 && (
                                    <div className="flex flex-wrap gap-2">
                                        {m.attachments.map((a) =>
                                            a.downloadable ? (
                                                <a key={a.id} href={`/attachments/${a.id}`} className="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs hover:bg-muted">
                                                    <Download className="size-3" />
                                                    {a.filename}
                                                </a>
                                            ) : (
                                                <span key={a.id} className="rounded-md border px-2 py-1 text-xs text-muted-foreground" title="Apagado pela retenção ou descartado">
                                                    {a.filename}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ),
                )}
            </div>
        </AppLayout>
    );
}
