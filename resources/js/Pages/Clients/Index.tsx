import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
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
            <PageHeader title="Clientes" description="A ficha viva de cada cliente: ERP, emails, contratos, pedidos em aberto. O gestor de clientes prepara briefings antes das reuniões." />

            {pending.length > 0 && (
                <Card className={pending.some((p) => p.breached) ? 'border-amber-300' : ''}>
                    <CardHeader>
                        <CardTitle>Pedidos de clientes por responder</CardTitle>
                        <CardDescription>Contados desde a chegada do email; SLA do contrato ou o da organização.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-1 text-sm">
                        {pending.map((p) => (
                            <Link key={p.email_id} href={`/inbox/${p.email_id}`} className="flex flex-wrap items-center gap-2 hover:underline">
                                {p.breached ? <Badge variant="destructive">{p.hours_waiting} h</Badge> : <Badge variant="secondary">{p.hours_waiting} h</Badge>}
                                <span>{p.subject}</span>
                                <span className="text-muted-foreground">· {p.from} · SLA {p.sla_hours} h</span>
                            </Link>
                        ))}
                    </CardContent>
                </Card>
            )}

            <form onSubmit={search} className="flex gap-2">
                <Input placeholder="Nome, NUIT, cidade ou sector…" value={query} onChange={(e) => setQuery(e.target.value)} />
                <Button type="submit" variant="outline">
                    <Search />
                    Procurar no ERP
                </Button>
            </form>

            {error ? (
                <EmptyState icon={Briefcase} title="ERP indisponível" description={error} />
            ) : accounts.length === 0 ? (
                <EmptyState icon={Briefcase} title="Sem resultados" description="Tente outro nome ou NUIT." />
            ) : (
                <Card className="divide-y py-0">
                    {accounts.map((a) => (
                        <Link key={a.id} href={`/clients/${a.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/50">
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium">{a.name}</span>
                                <span className="block text-xs text-muted-foreground">{[a.id, a.sector, a.city].filter(Boolean).join(' · ')}</span>
                            </span>
                            {a.status && a.status !== 'active' && <Badge variant="outline">{a.status}</Badge>}
                        </Link>
                    ))}
                </Card>
            )}
        </AppLayout>
    );
}
