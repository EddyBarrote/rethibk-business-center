import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import { PageHeader } from '@/Components/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import type { ContractRow } from '@/Pages/Contracts/Index';
import { ContractForm, type ContractOptions } from '@/Pages/Contracts/Form';

export default function ContractShow({ contract, ...options }: { contract: ContractRow } & ContractOptions) {
    return (
        <AppLayout>
            <Head title={contract.title} />
            <div>
                <Link href="/contracts" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Contratos
                </Link>
            </div>
            <PageHeader title={contract.title} description={`${contract.party_type_label} · ${contract.party_name}`} />
            <Card>
                <CardContent>
                    <ContractForm contract={contract} options={options} />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
