import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';

interface Item {
    id: number;
    type_label: string;
    title: string;
    content: string;
    is_external: boolean;
    created_at: string;
}

export default function KnowledgeShow({ item }: { item: Item }) {
    return (
        <AppLayout>
            <Head title={item.title} />
            <div>
                <Link href="/knowledge" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Memória
                </Link>
            </div>
            <PageHeader title={item.title} description={`${item.type_label} · ${dateTime(item.created_at)}`} actions={item.is_external ? <Badge variant="outline">conteúdo externo</Badge> : undefined} />
            <Card>
                <CardContent className="text-sm leading-relaxed whitespace-pre-wrap">{item.content}</CardContent>
            </Card>
        </AppLayout>
    );
}
