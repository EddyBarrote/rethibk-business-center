import { Head, Link, router } from '@inertiajs/react';
import { Inbox, Paperclip, Search, ShieldAlert } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { CategoryBadge, PriorityDot } from '@/Components/CategoryBadge';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
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

export default function InboxIndex({ messages, filters, categories, mailboxes, drafts }: Props) {
    const [query, setQuery] = useState(filters.q);
    const apply = (changes: Partial<Props['filters']>) =>
        router.get('/inbox', Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== null && v !== '' && v !== undefined)), { preserveState: true });

    const search = (event: FormEvent) => {
        event.preventDefault();
        apply({ q: query });
    };

    return (
        <AppLayout>
            <Head title="Caixa" />
            <PageHeader title="Caixa de entrada" description="O correio das caixas dos agentes, já triado: categoria, resumo, prazo e a quem foi encaminhado." />

            <div className="flex flex-wrap items-center gap-2">
                {views.map((view) => (
                    <Button key={view.value} size="sm" variant={filters.view === view.value ? 'default' : 'outline'} onClick={() => apply({ view: view.value, category: null })}>
                        {view.label}
                        {view.value === 'drafts' && drafts > 0 && <Badge className="ml-1 bg-amber-500">{drafts}</Badge>}
                    </Button>
                ))}
                <form onSubmit={search} className="ml-auto flex gap-2">
                    <Input placeholder="Assunto, remetente…" value={query} onChange={(e) => setQuery(e.target.value)} className="w-56" />
                    <Button type="submit" size="sm" variant="outline">
                        <Search />
                    </Button>
                </form>
            </div>

            {filters.view === 'inbound' && (
                <div className="flex flex-wrap items-center gap-2">
                    <button type="button" onClick={() => apply({ category: null })} className={cn('rounded-full border px-3 py-1 text-xs', !filters.category && 'border-primary bg-accent')}>
                        Todas
                    </button>
                    <button type="button" onClick={() => apply({ category: 'none' })} className={cn('rounded-full border px-3 py-1 text-xs', filters.category === 'none' && 'border-primary bg-accent')}>
                        Por triar
                    </button>
                    {categories.map((category) => (
                        <button
                            key={category.value}
                            type="button"
                            onClick={() => apply({ category: category.value })}
                            className={cn('rounded-full border px-3 py-1 text-xs', filters.category === category.value && 'border-primary bg-accent')}
                        >
                            {category.label}
                            {category.count > 0 && <span className="ml-1 text-muted-foreground">{category.count}</span>}
                        </button>
                    ))}
                    {mailboxes.length > 1 && (
                        <NativeSelect className="ml-auto w-56" value={filters.mailbox ?? ''} onChange={(e) => apply({ mailbox: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">Todas as caixas</option>
                            {mailboxes.map((mailbox) => (
                                <option key={mailbox.id} value={mailbox.id}>
                                    {mailbox.address}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                </div>
            )}

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
                <Card className="divide-y py-0">
                    {messages.data.map((message) => (
                        <Link key={message.id} href={`/inbox/${message.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-muted/50 sm:flex-row sm:items-start sm:gap-4">
                            <div className="flex items-center gap-2 sm:w-56 sm:shrink-0">
                                <PriorityDot priority={message.priority} />
                                <span className={cn('truncate text-sm', message.unread && 'font-semibold')}>{message.direction === 'inbound' ? message.from : message.mailbox}</span>
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={cn('truncate text-sm', message.unread && 'font-semibold')}>{message.subject}</span>
                                    {message.direction === 'inbound' && <CategoryBadge category={message.category} label={message.category_label} />}
                                    {message.direction === 'outbound' && <Badge variant="secondary">{message.status_label}</Badge>}
                                    {message.injection && (
                                        <Badge variant="destructive">
                                            <ShieldAlert />
                                            suspeito
                                        </Badge>
                                    )}
                                    {(message.attachments_count ?? 0) > 0 && <Paperclip className="size-3.5 text-muted-foreground" />}
                                </div>
                                {message.summary && <p className="line-clamp-1 text-sm text-muted-foreground">{message.summary}</p>}
                                <p className="text-xs text-muted-foreground">
                                    {message.routed_to && <>Encaminhado a {message.routed_to} · </>}
                                    {message.deadline_at && <>Prazo {dateTime(message.deadline_at)} · </>}
                                    {message.status === 'processing' && <>Em triagem · </>}
                                    {message.mailbox}
                                </p>
                            </div>
                            <span className="text-xs whitespace-nowrap text-muted-foreground">{dateTime(message.date)}</span>
                        </Link>
                    ))}
                </Card>
            )}

            <Pagination page={messages} />
        </AppLayout>
    );
}
