import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
import type { BriefingSummary } from '@/types';

export default function BriefingShow({ briefing }: { briefing: BriefingSummary }) {
    return (
        <AppLayout>
            <Head title={briefing.title} />
            <div>
                <Link href="/briefings" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Briefings
                </Link>
            </div>
            <PageHeader title={briefing.title} description={`${briefing.type_label} · ${briefing.agent ?? ''} · ${dateTime(briefing.created_at)}`} />

            {briefing.decisions_pending.length > 0 && (
                <Card className="border-amber-300 bg-amber-50">
                    <CardHeader>
                        <CardTitle className="text-amber-900">Precisa da sua decisão</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2">
                        <ul className="grid gap-2 text-sm">
                            {briefing.decisions_pending.map((d, i) => (
                                <li key={i} className="flex flex-wrap items-center gap-2">
                                    {d.link ? (
                                        <Link href={d.link} className="font-medium text-amber-900 underline">
                                            {d.title}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">{d.title}</span>
                                    )}
                                    {d.owner && <span className="text-xs text-amber-800">· {d.owner}</span>}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardContent>
                    <Markdown>{briefing.content ?? ''}</Markdown>
                </CardContent>
            </Card>

            {briefing.run_id && (
                <Link href={`/runs/${briefing.run_id}`} className="text-sm text-primary hover:underline">
                    Ver como o agente preparou este briefing
                </Link>
            )}
        </AppLayout>
    );
}
