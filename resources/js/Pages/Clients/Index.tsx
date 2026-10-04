import { Head, router } from '@inertiajs/react';
import { Briefcase, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EntityRow, ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';

interface Account {
    id: string;
    name: string;
    sector?: string;
    city?: string;
    status?: string;
}

interface Pending {
    email_id: number;
    subject: string | null;
    from: string | null;
    hours_waiting: number;
    sla_hours: number;
    breached: boolean;
}

export default function ClientsIndex({ accounts, q, error, pending }: { accounts: Account[]; q: string; error: string | null; pending: Pending[] }) {
    const [query, setQuery] = useState(q);
    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get('/clients', query ? { q: query } : {}, { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Clientes" />
            <PageHeader
                title="Clientes"
                description="A ficha viva de cada cliente: ERP, emails, contratos, pedidos em aberto. O gestor de clientes prepara briefings antes das reuniões."
            />

            {pending.length > 0 && (
                <Section
                    title="Pedidos por responder"
                    action={<span className="text-xs text-muted-foreground">Desde a chegada do email; SLA do contrato ou da organização</span>}
                >
                    <ListPanel>
                        {pending.map((p) => (
                            <EntityRow
                                key={p.email_id}
                                href={`/inbox/${p.email_id}`}
                                leading={<StatusDot tone={p.breached ? 'danger' : 'warning'} pulse={false} />}
                                title={p.subject ?? '(sem assunto)'}
                                subtitle={p.from}
                                meta={<span className="font-mono tabular-nums">SLA {p.sla_hours} h</span>}
                                trailing={
                                    <StatusBadge tone={p.breached ? 'danger' : 'idle'} dot={false} className="font-mono tabular-nums">
                                        {p.hours_waiting} h
                                    </StatusBadge>
                                }
                            />
                        ))}
                    </ListPanel>
                </Section>
            )}

            <Section title="Clientes no ERP">
                <form onSubmit={search} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Nome, NUIT, cidade ou sector…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="pl-8"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Procurar no ERP
                    </Button>
                </form>

                {error ? (
                    <EmptyState icon={Briefcase} title="ERP indisponível" description={error} />
                ) : accounts.length === 0 ? (
                    <EmptyState icon={Briefcase} title="Sem resultados" description="Tente outro nome ou NUIT." />
                ) : (
                    <ListPanel>
                        {accounts.map((a) => (
                            <EntityRow
                                key={a.id}
                                href={`/clients/${a.id}`}
                                leading={<Monogram name={a.name} />}
                                title={a.name}
                                subtitle={[a.sector, a.city].filter(Boolean).join(' · ') || undefined}
                                meta={<span className="font-mono">{a.id}</span>}
                                trailing={a.status && a.status !== 'active' ? <StatusBadge tone="idle">{a.status}</StatusBadge> : undefined}
                            />
                        ))}
                    </ListPanel>
                )}
            </Section>
        </AppLayout>
    );
}
