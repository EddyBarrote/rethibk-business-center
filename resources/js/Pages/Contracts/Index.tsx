import { Head, Link, router } from '@inertiajs/react';
import { FileSignature, Plus } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { date, mzn } from '@/lib/format';
import { ContractForm, type ContractData, type ContractOptions } from '@/Pages/Contracts/Form';

export interface ContractRow extends ContractData {
    id: number;
    party_type_label: string;
    status_label: string;
    days_left: number | null;
    owner: string | null;
    in_notice: boolean;
}

export default function ContractsIndex({ contracts, filter, ...options }: { contracts: ContractRow[]; filter: string | null } & ContractOptions) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout>
            <Head title="Contratos" />
            <PageHeader
                title="Contratos"
                description="Contratos de clientes e fornecedores. Ao entrar no período de aviso, o responsável é avisado e o agente da área prepara a renovação."
                actions={
                    <Button onClick={() => setAdding(!adding)} variant={adding ? 'outline' : 'default'}>
                        <Plus />
                        Novo contrato
                    </Button>
                }
            />

            {adding && (
                <Card>
                    <CardHeader>
                        <CardTitle>Novo contrato</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-4">
                        <ContractForm options={options} onDone={() => setAdding(false)} />
                    </CardContent>
                </Card>
            )}

            <div className="flex gap-2">
                {[{ value: null, label: 'Todos' }, ...options.partyTypes].map((t) => (
                    <Button key={t.value ?? 'all'} size="sm" variant={filter === t.value ? 'default' : 'outline'} onClick={() => router.get('/contracts', t.value ? { party_type: t.value } : {})}>
                        {t.label}
                    </Button>
                ))}
            </div>

            {contracts.length === 0 ? (
                <EmptyState icon={FileSignature} title="Sem contratos" description="Registe os contratos com data de fim para receber os avisos de renovação." />
            ) : (
                <Card className="divide-y py-0">
                    {contracts.map((c) => (
                        <Link key={c.id} href={`/contracts/${c.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/50">
                            <Badge variant="secondary">{c.party_type_label}</Badge>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">{c.title}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {c.party_name} · {mzn(c.value)} · fim {date(c.ends_at)}
                                    {c.owner && ` · ${c.owner}`}
                                </span>
                            </span>
                            {c.in_notice && <Badge className="bg-amber-500">{c.days_left !== null && c.days_left < 0 ? 'terminou' : `${c.days_left} dias`}</Badge>}
                            <Badge variant="outline">{c.status_label}</Badge>
                        </Link>
                    ))}
                </Card>
            )}
        </AppLayout>
    );
}
