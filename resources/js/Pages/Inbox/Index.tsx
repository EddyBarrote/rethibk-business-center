import { Head, Link, router } from '@inertiajs/react';
import { Inbox, Paperclip, Search, ShieldAlert } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import { CategoryBadge } from '@/Components/CategoryBadge';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, deadline } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

export interface EmailSummary {
    id: number;
    direction: 'inbound' | 'outbound';
    from: string | null;
    from_address: string | null;
    subject: string;
    summary: string | null;
    category: string | null;
    category_label: string | null;
    priority: string | null;
    status: string;
    status_label: string;
    mailbox: string | null;
    routed_to: string | null;
    deadline_at: string | null;
    injection: boolean;
    unread: boolean;
    attachments_count: number | null;
    date: string;
}

interface Props {
    messages: Paginated<EmailSummary>;
    filters: { category: string | null; mailbox: number | null; view: 'inbound' | 'drafts' | 'sent'; q: string };
    categories: (Option & { count: number })[];
    mailboxes: { id: number; address: string; agent: string | null; status: string }[];
    drafts: number;
}

const views = [
    { value: 'inbound', label: 'Recebidos' },
    { value: 'drafts', label: 'Rascunhos' },
    { value: 'sent', label: 'Enviados' },
] as const;

/** Email lifecycle (EmailStatus) mapped to the shared tones. */
export const emailTone = (status: string): Tone =>
    (({
        received: 'idle',
        processing: 'running',
        processed: 'success',
        failed: 'danger',
        draft: 'warning',
        queued: 'running',
        sent: 'success',
        bounced: 'danger',
    })[status] as Tone) ?? 'idle';

const priorityTone = (priority: string | null): Tone | null => (priority === 'urgent' ? 'danger' : priority === 'high' ? 'warning' : null);

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md px-2.5 text-xs transition-colors',
                active ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

export default function InboxIndex({ messages, filters, categories, mailboxes, drafts }: Props) {
    const [query, setQuery] = useState(filters.q);
    const apply = (changes: Partial<Props['filters']>) =>
        router.get(
            '/inbox',
            Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== null && v !== '' && v !== undefined)),
            { preserveState: true },
        );

    const search = (event: FormEvent) => {
        event.preventDefault();
        apply({ q: query });
    };

    return (
        <AppLayout>
            <Head title="Emails" />
            <PageHeader
                title="Emails"
                description="O correio das caixas dos agentes, já triado: categoria, resumo, prazo e a quem foi encaminhado."
            />

            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex items-center gap-0.5 rounded-lg border bg-card p-0.5">
                        {views.map((view) => (
                            <button
                                key={view.value}
                                type="button"
                                onClick={() => apply({ view: view.value, category: null })}
                                className={cn(
                                    'inline-flex h-7 items-center gap-1.5 rounded-md px-3 text-sm transition-colors',
                                    filters.view === view.value
                                        ? 'bg-accent font-medium text-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {view.label}
                                {view.value === 'drafts' && drafts > 0 && (
                                    <span className="rounded-full bg-status-warning/15 px-1.5 font-mono text-[11px] text-status-warning tabular-nums">
                                        {drafts}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>

                    <div className="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                        {filters.view === 'inbound' && mailboxes.length > 1 && (
                            <NativeSelect
                                className="h-8 w-full text-sm sm:w-56"
                                value={filters.mailbox ?? ''}
                                onChange={(e) => apply({ mailbox: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">Todas as caixas</option>
                                {mailboxes.map((mailbox) => (
                                    <option key={mailbox.id} value={mailbox.id}>
                                        {mailbox.address}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        <form onSubmit={search} className="relative w-full sm:w-auto">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Assunto, remetente…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                className="h-8 w-full pl-8 text-sm sm:w-60"
                            />
                        </form>
                    </div>
                </div>

                {filters.view === 'inbound' && (
                    <div className="flex flex-wrap items-center gap-0.5">
                        <Chip active={!filters.category} onClick={() => apply({ category: null })}>
                            Todas
                        </Chip>
                        <Chip active={filters.category === 'none'} onClick={() => apply({ category: 'none' })}>
                            Por triar
                        </Chip>
                        {/* Only the categories with mail in the last 30 days, plus the one being looked at: no row of empty filters. */}
                        {categories
                            .filter((category) => category.count > 0 || filters.category === category.value)
                            .map((category) => (
                                <Chip
                                    key={category.value}
                                    active={filters.category === category.value}
                                    onClick={() => apply({ category: category.value })}
                                >
                                    {category.label}
                                    {category.count > 0 && (
                                        <span className="font-mono text-[11px] text-muted-foreground tabular-nums">{category.count}</span>
                                    )}
                                </Chip>
                            ))}
                    </div>
                )}
            </div>

            {messages.data.length === 0 ? (
                <EmptyState
                    icon={Inbox}
                    title="Nada por aqui"
                    description={
                        mailboxes.length === 0
                            ? 'Ainda não há caixas de correio ligadas. A Rethink configura a caixa de cada agente na consola de administração.'
                            : 'Os emails aparecem aqui assim que chegam às caixas dos agentes (a cada minuto).'
                    }
                />
            ) : (
                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                    <div className="hidden items-center gap-3 bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground sm:flex">
                        <span className="w-2" />
                        <span className="w-40 lg:w-52">De</span>
                        <span className="min-w-0 flex-1">Assunto</span>
                        <span className="hidden w-36 text-right md:block">Categoria</span>
                        <span className="w-16 text-right">Recebido</span>
                    </div>
                    {messages.data.map((message) => {
                        const tone = priorityTone(message.priority);
                        const inbound = message.direction === 'inbound';

                        return (
                            <Link
                                key={message.id}
                                href={`/inbox/${message.id}`}
                                className={cn(
                                    'group flex items-start gap-3 px-4 py-3 transition-colors hover:bg-accent/60 sm:items-center sm:py-2.5',
                                    message.unread && 'bg-primary/[0.03]',
                                )}
                            >
                                <span
                                    className="flex w-2 shrink-0 justify-center pt-1.5 sm:pt-0"
                                    title={tone === 'danger' ? 'Urgente' : tone === 'warning' ? 'Prioridade alta' : undefined}
                                >
                                    {tone ? (
                                        <StatusDot tone={tone} pulse={false} />
                                    ) : message.unread ? (
                                        <span className="size-1.5 rounded-full bg-primary" />
                                    ) : null}
                                </span>

                                <span
                                    className={cn(
                                        'hidden w-40 shrink-0 truncate text-sm sm:block lg:w-52',
                                        message.unread ? 'font-semibold' : 'text-muted-foreground',
                                    )}
                                    title={message.from_address ?? undefined}
                                >
                                    {inbound ? message.from : message.mailbox}
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-baseline gap-2 sm:hidden">
                                        <span
                                            className={cn(
                                                'min-w-0 flex-1 truncate text-sm',
                                                message.unread ? 'font-semibold' : 'text-muted-foreground',
                                            )}
                                        >
                                            {inbound ? message.from : message.mailbox}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{ago(message.date)}</span>
                                    </div>
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className={cn('truncate text-sm', message.unread ? 'font-semibold' : 'font-medium')}>
                                            {message.subject}
                                        </span>
                                        {message.injection && (
                                            <StatusBadge tone="danger" dot={false} title="Possível tentativa de manipulação do agente">
                                                <ShieldAlert className="size-3" />
                                                suspeito
                                            </StatusBadge>
                                        )}
                                        {(message.attachments_count ?? 0) > 0 && <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />}
                                        {message.summary && (
                                            <span className="hidden min-w-0 flex-1 truncate text-sm text-muted-foreground xl:inline">
                                                — {message.summary}
                                            </span>
                                        )}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {[
                                            message.routed_to && `Encaminhado a ${message.routed_to}`,
                                            message.deadline_at && `Prazo ${deadline(message.deadline_at)}`,
                                            message.mailbox,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </div>

                                <div className="hidden w-36 shrink-0 items-center justify-end gap-2 md:flex">
                                    {inbound && <CategoryBadge category={message.category} label={message.category_label} />}
                                    {(!inbound || message.status === 'processing' || message.status === 'failed') && (
                                        <StatusBadge tone={emailTone(message.status)}>{message.status_label}</StatusBadge>
                                    )}
                                </div>

                                <span
                                    className="hidden w-16 shrink-0 text-right text-xs whitespace-nowrap text-muted-foreground tabular-nums sm:block"
                                    title={dateTime(message.date)}
                                >
                                    {ago(message.date)}
                                </span>
                            </Link>
                        );
                    })}
                </div>
            )}

            <Pagination page={messages} noun={['email', 'emails']} />
        </AppLayout>
    );
}
