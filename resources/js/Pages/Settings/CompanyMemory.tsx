import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bot, Building2, Check, Lock, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { ListPanel, Section } from '@/Components/Blocks';
import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { NativeSelect } from '@/Components/ui/native-select';
import { Progress } from '@/Components/ui/progress';
import { Textarea } from '@/Components/ui/textarea';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, date, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { DomainDot } from '@/Pages/Knowledge/Index';

interface DomainOption {
    id: number;
    name: string;
    color: string | null;
    restricted: boolean;
}

interface Fact {
    id: number;
    section: string;
    content: string;
    status: string;
    domain: DomainOption | null;
    proposed_by: string | null;
    written_by: string | null;
    reviewed_by: string | null;
    task: { id: number; title: string } | null;
    replaces: { id: number; content: string } | null;
    created_at: string;
    can_edit: boolean;
}

interface Props {
    sections: { value: string; label: string; hint: string }[];
    facts: Fact[];
    proposals: Fact[];
    domains: DomainOption[];
    usage: { used: number; limit: number };
    can: { write: boolean; write_company: boolean };
}

type Mode = { kind: 'new' } | { kind: 'edit'; fact: Fact } | { kind: 'review'; fact: Fact };

const MAX = 1000;

/** Who wrote the fact and who let it in, in one quiet line. */
function source(fact: Fact) {
    if (fact.proposed_by) {
        return fact.reviewed_by ? `Proposto por ${fact.proposed_by}, aprovado por ${fact.reviewed_by}` : `Proposto por ${fact.proposed_by}`;
    }

    return fact.written_by ? `Escrito por ${fact.written_by}` : 'Registado antes da memória da empresa';
}

function DomainBadge({ domain }: { domain: DomainOption | null }) {
    if (domain === null) {
        return null;
    }

    return (
        <StatusBadge tone="idle" dot={false} title={`Só os agentes que abrem o domínio ${domain.name}`}>
            {domain.restricted ? <Lock className="size-3" /> : <DomainDot color={domain.color} />}
            {domain.name}
        </StatusBadge>
    );
}

export default function CompanyMemory({ sections, facts, proposals, domains, usage, can }: Props) {
    const [mode, setMode] = useState<Mode | null>(null);
    const form = useForm({ section: sections[0]?.value ?? 'about', content: '', knowledge_domain_id: '' as string });
    const label = (value: string) => sections.find((s) => s.value === value)?.label ?? value;
    const over = usage.used > usage.limit;

    const open = (next: Mode, section?: string) => {
        form.clearErrors();
        form.setData(
            next.kind === 'new'
                ? { section: section ?? sections[0].value, content: '', knowledge_domain_id: can.write_company ? '' : String(domains[0]?.id ?? '') }
                : {
                      section: next.fact.section,
                      content: next.fact.content,
                      knowledge_domain_id: next.fact.domain ? String(next.fact.domain.id) : '',
                  },
        );
        setMode(next);
    };

    const submit = () => {
        if (mode === null) {
            return;
        }

        const options = { preserveScroll: true, onSuccess: () => setMode(null) };
        form.transform((data) => ({ ...data, knowledge_domain_id: data.knowledge_domain_id === '' ? null : Number(data.knowledge_domain_id) }));

        if (mode.kind === 'new') {
            form.post('/settings/company-memory', options);
        } else if (mode.kind === 'edit') {
            form.put(`/settings/company-memory/${mode.fact.id}`, options);
        } else {
            form.post(`/settings/company-memory/${mode.fact.id}/approve`, options);
        }
    };

    const approve = (fact: Fact) =>
        router.post(
            `/settings/company-memory/${fact.id}/approve`,
            { section: fact.section, content: fact.content, knowledge_domain_id: fact.domain?.id ?? null },
            { preserveScroll: true },
        );

    return (
        <SettingsLayout>
            <Head title="Memória da empresa" />
            <PageHeader
                title="Memória da empresa"
                description="O que vale para toda a empresa. Todos os agentes lêem estes factos em cada conversa e tarefa. Os agentes propõem factos novos; uma pessoa aprova-os antes de chegarem a todos."
                actions={
                    can.write && (
                        <Button onClick={() => open({ kind: 'new' })}>
                            <Plus />
                            Novo facto
                        </Button>
                    )
                }
            />

            <div className="flex flex-col gap-8">
                <div className="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                        <span>Espaço no prompt dos agentes</span>
                        <span className={cn('font-mono text-xs tabular-nums', over ? 'text-status-warning' : 'text-muted-foreground')}>
                            {number(usage.used)} de {number(usage.limit)} caracteres
                        </span>
                    </div>
                    <Progress value={Math.min(100, (usage.used / usage.limit) * 100)} />
                    {over && (
                        <p className="text-xs text-status-warning">
                            Passou o limite: os factos mais antigos ficam de fora. Junte ou apague os que já não servem.
                        </p>
                    )}
                </div>

                {proposals.length > 0 && (
                    <Section
                        title="Propostas dos agentes"
                        action={<span className="font-mono text-xs text-status-warning tabular-nums">{proposals.length}</span>}
                    >
                        <ListPanel>
                            {proposals.map((fact) => (
                                <div key={fact.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start">
                                    <Bot className="mt-0.5 hidden size-4 shrink-0 text-muted-foreground sm:block" />
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <p className="text-sm break-words whitespace-pre-line">{fact.content}</p>
                                        {fact.replaces && (
                                            <p className="text-xs break-words text-muted-foreground">
                                                Substitui: <span className="line-through">{fact.replaces.content}</span>
                                            </p>
                                        )}
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                            <span>{label(fact.section)}</span>
                                            <span aria-hidden>·</span>
                                            <span>{source(fact)}</span>
                                            {fact.task && (
                                                <>
                                                    <span aria-hidden>·</span>
                                                    <Link href={`/tasks/${fact.task.id}`} className="truncate underline-offset-2 hover:underline">
                                                        {fact.task.title}
                                                    </Link>
                                                </>
                                            )}
                                            <span aria-hidden>·</span>
                                            <span>{ago(fact.created_at)}</span>
                                            <DomainBadge domain={fact.domain} />
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(`/settings/company-memory/${fact.id}/reject`, {}, { preserveScroll: true })}
                                        >
                                            <X />
                                            Rejeitar
                                        </Button>
                                        <Button variant="outline" size="sm" onClick={() => open({ kind: 'review', fact })}>
                                            <Pencil />
                                            Editar
                                        </Button>
                                        <Button size="sm" onClick={() => approve(fact)}>
                                            <Check />
                                            Aprovar
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </ListPanel>
                    </Section>
                )}

                {facts.length === 0 && proposals.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="A memória da empresa está vazia"
                        description="Escreva os factos que todos os agentes devem saber sempre: o que a empresa faz, os clientes-chave, as regras e os preços. Os agentes também vão propor factos que aprendem."
                        action={
                            can.write && (
                                <Button onClick={() => open({ kind: 'new' })}>
                                    <Plus />
                                    Novo facto
                                </Button>
                            )
                        }
                        example={<p className="text-sm">As propostas acima de 500 000 MT precisam da assinatura da Direcção-Geral.</p>}
                    />
                ) : (
                    sections.map((section) => {
                        const rows = facts.filter((f) => f.section === section.value);

                        return (
                            <Section
                                key={section.value}
                                title={section.label}
                                action={
                                    can.write && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => open({ kind: 'new' }, section.value)}
                                            aria-label={`Novo facto em ${section.label}`}
                                        >
                                            <Plus />
                                            Adicionar
                                        </Button>
                                    )
                                }
                            >
                                {rows.length === 0 ? (
                                    <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">{section.hint}</p>
                                ) : (
                                    <ListPanel>
                                        {rows.map((fact) => (
                                            <div key={fact.id} className="group flex items-start gap-3 px-4 py-2.5">
                                                <div className="min-w-0 flex-1 space-y-0.5">
                                                    <p className="text-sm break-words whitespace-pre-line">{fact.content}</p>
                                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                        <span>{source(fact)}</span>
                                                        <span aria-hidden>·</span>
                                                        <span>{date(fact.created_at)}</span>
                                                        <DomainBadge domain={fact.domain} />
                                                    </div>
                                                </div>
                                                {fact.can_edit && (
                                                    <div className="flex shrink-0 items-center gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            onClick={() => open({ kind: 'edit', fact })}
                                                            aria-label="Editar facto"
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <ConfirmDialog
                                                            title="Apagar este facto?"
                                                            description="Os agentes deixam de o saber a partir da próxima conversa. Fica registado quem o apagou."
                                                            confirmLabel="Apagar facto"
                                                            destructive
                                                            onConfirm={() => router.delete(`/settings/company-memory/${fact.id}`, { preserveScroll: true })}
                                                            trigger={
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon-sm"
                                                                    aria-label="Apagar facto"
                                                                    className="hover:text-status-danger"
                                                                >
                                                                    <Trash2 />
                                                                </Button>
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </ListPanel>
                                )}
                            </Section>
                        );
                    })
                )}
            </div>

            <FormDialog
                open={mode !== null}
                onOpenChange={(next) => !next && setMode(null)}
                title={mode?.kind === 'review' ? 'Rever proposta' : mode?.kind === 'edit' ? 'Editar facto' : 'Novo facto'}
                description={
                    mode?.kind === 'review'
                        ? `De ${mode.fact.proposed_by}. Corrija o que for preciso antes de aprovar.`
                        : 'Uma ou duas frases que se entendem sozinhas, com nomes, datas e valores.'
                }
                submitLabel={mode?.kind === 'review' ? 'Aprovar' : 'Guardar'}
                processing={form.processing}
                disabled={form.data.content.trim() === ''}
                onSubmit={submit}
            >
                <Field id="section" label="Secção" error={form.errors.section}>
                    <NativeSelect id="section" value={form.data.section} onChange={(e) => form.setData('section', e.target.value)}>
                        {sections.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="content" label="Facto" error={form.errors.content} hint={`${form.data.content.length} de ${MAX} caracteres.`}>
                    <Textarea
                        id="content"
                        autoFocus
                        rows={4}
                        maxLength={MAX}
                        value={form.data.content}
                        onChange={(e) => form.setData('content', e.target.value)}
                        placeholder="A Mozal paga a 30 dias e exige factura com o número da ordem de compra."
                    />
                </Field>
                <Field
                    id="knowledge_domain_id"
                    label="Quem o sabe"
                    error={form.errors.knowledge_domain_id}
                    hint="Limitado a um domínio, só o sabem os agentes que abrem esse domínio."
                >
                    <NativeSelect
                        id="knowledge_domain_id"
                        value={form.data.knowledge_domain_id}
                        onChange={(e) => form.setData('knowledge_domain_id', e.target.value)}
                    >
                        {can.write_company && <option value="">Todos os agentes</option>}
                        {domains.map((d) => (
                            <option key={d.id} value={String(d.id)}>
                                Só o domínio {d.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            </FormDialog>
        </SettingsLayout>
    );
}
