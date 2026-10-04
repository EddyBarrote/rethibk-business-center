import { Head, router } from '@inertiajs/react';
import { FileSignature, Plus } from 'lucide-react';
import { useState } from 'react';

import { EntityRow, ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { date, mzn } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ContractForm, type ContractData, type ContractOptions } from '@/Pages/Contracts/Form';

export interface ContractRow extends ContractData {
    id: number;
    party_type_label: string;
    status_label: string;
    days_left: number | null;
    owner: string | null;
    in_notice: boolean;
}

export const contractTone = (status: string): Tone =>
    (({ active: 'success', renewing: 'warning', ended: 'idle', cancelled: 'idle' })[status] as Tone) ?? 'idle';

/** "Termina em 12 dias" as a warning badge once the contract is inside its notice period. */
export function NoticeBadge({ contract }: { contract: ContractRow }) {
    if (!contract.in_notice) {
        return null;
    }

    const ended = contract.days_left !== null && contract.days_left < 0;

    return (
        <StatusBadge tone={ended ? 'danger' : 'warning'} title={`Fim a ${date(contract.ends_at)}`}>
            {ended ? 'terminou' : <span className="font-mono tabular-nums">{contract.days_left} dias</span>}
        </StatusBadge>
    );
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
                <Section title="Novo contrato">
                    <ContractForm options={options} onDone={() => setAdding(false)} />
                </Section>
            )}

            <Section
                title="Contratos"
                action={
                    <div className="inline-flex rounded-lg border bg-card p-0.5">
                        {[{ value: null, label: 'Todos' }, ...options.partyTypes].map((t) => (
                            <button
                                key={t.value ?? 'all'}
                                type="button"
                                onClick={() => router.get('/contracts', t.value ? { party_type: t.value } : {})}
                                className={cn(
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                    filter === t.value ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                }
            >
                {contracts.length === 0 ? (
                    <EmptyState
                        icon={FileSignature}
                        title="Sem contratos"
                        description="Registe o primeiro contrato com a data de fim para começar a receber os avisos de renovação."
                    />
                ) : (
                    <ListPanel>
                        {contracts.map((c) => (
                            <EntityRow
                                key={c.id}
                                href={`/contracts/${c.id}`}
                                leading={<Monogram name={c.party_name} />}
                                title={c.title}
                                subtitle={[c.party_name, c.party_type_label, c.owner].filter(Boolean).join(' · ')}
                                meta={
                                    <>
                                        <span className="font-mono text-foreground tabular-nums">{mzn(c.value)}</span>
                                        <span className="tabular-nums">fim {date(c.ends_at)}</span>
                                    </>
                                }
                                trailing={
                                    <>
                                        <NoticeBadge contract={c} />
                                        <StatusBadge tone={contractTone(c.status)}>{c.status_label}</StatusBadge>
                                    </>
                                }
                            />
                        ))}
                    </ListPanel>
                )}
            </Section>
        </AppLayout>
    );
}
