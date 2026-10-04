import { Head, usePage } from '@inertiajs/react';
import { Bot, CheckSquare, FileText } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import type { SharedProps } from '@/types';

export default function Dashboard() {
    const { auth } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0];
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Bom dia' : hour < 19 ? 'Boa tarde' : 'Boa noite';

    return (
        <AppLayout>
            <Head title="Painel" />

            <PageHeader title={`${greeting}, ${firstName}`} description="O resumo do dia, as decisões pendentes e o estado dos agentes." />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Briefing do dia</CardTitle>
                        <CardDescription>Preparado todas as manhãs pelo Chief of Staff.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <EmptyState
                            icon={FileText}
                            title="Ainda sem briefings"
                            description="O Chief of Staff começa a preparar o briefing diário às 06:30 dos dias úteis quando for activado (E04)."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Aprovações pendentes</CardTitle>
                        <CardDescription>Acções dos agentes à espera de decisão.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <EmptyState
                            icon={CheckSquare}
                            title="Nada à espera"
                            description="Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui (E02)."
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Agentes</CardTitle>
                    <CardDescription>Os seis agentes da plataforma, o estado e o nível de autonomia.</CardDescription>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        icon={Bot}
                        title="Nenhum agente activo"
                        description="Os agentes são configurados a partir da E02. Triagem, Chief of Staff, Finanças, Procurement, RH e Gestor de Clientes entram por esta ordem."
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
