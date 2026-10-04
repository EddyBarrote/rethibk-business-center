import { Head } from '@inertiajs/react';

import { Properties, Property } from '@/Components/Blocks';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { date, mzn } from '@/lib/format';
import { ContractForm, type ContractOptions } from '@/Pages/Contracts/Form';
import { contractTone, type ContractRow, NoticeBadge } from '@/Pages/Contracts/Index';

export default function ContractShow({ contract, ...options }: { contract: ContractRow } & ContractOptions) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Contratos', href: '/contracts' }, { label: contract.title }]}>
            <Head title={contract.title} />
            <PageHeader title={contract.title} description={`${contract.party_type_label} · ${contract.party_name}`} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0">
                    <ContractForm contract={contract} options={options} />
                </div>

                <Properties className="h-fit">
                    <Property label="Estado">
                        <span className="inline-flex flex-wrap gap-1.5">
                            <StatusBadge tone={contractTone(contract.status)}>{contract.status_label}</StatusBadge>
                            <NoticeBadge contract={contract} />
                        </span>
                    </Property>
                    <Property label="Entidade">{contract.party_name}</Property>
                    <Property label="Tipo">{contract.party_type_label}</Property>
                    <Property label="Id no ERP">{contract.party_ref && <span className="font-mono">{contract.party_ref}</span>}</Property>
                    <Property label="Referência">{contract.reference && <span className="font-mono">{contract.reference}</span>}</Property>
                    <Property label="Valor">
                        {contract.value !== null ? <span className="font-mono tabular-nums">{mzn(contract.value)}</span> : null}
                    </Property>
                    <Property label="Início">{contract.starts_at ? date(contract.starts_at) : null}</Property>
                    <Property label="Fim">{contract.ends_at ? date(contract.ends_at) : null}</Property>
                    <Property label="Faltam">
                        {contract.days_left !== null ? <span className="font-mono tabular-nums">{contract.days_left} dias</span> : null}
                    </Property>
                    <Property label="Aviso">
                        <span className="font-mono tabular-nums">{contract.notice_days} dias</span> antes
                    </Property>
                    <Property label="Renovação">{contract.auto_renews ? 'Automática' : 'Manual'}</Property>
                    <Property label="Responsável">{contract.owner}</Property>
                </Properties>
            </div>
        </AppLayout>
    );
}
