import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CircleCheck, CircleDashed, CircleAlert, Loader2, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Check {
    key: string;
    label: string;
    status: 'ready' | 'unchecked' | 'needs_setup';
    detail: string;
    fix: string | null;
    href: string | null;
    checked_at: string | null;
}

const states = {
    ready: { label: 'Pronta', icon: CircleCheck, tone: 'text-status-success' },
    unchecked: { label: 'Não verificada', icon: CircleDashed, tone: 'text-muted-foreground' },
    needs_setup: { label: 'Precisa de configuração', icon: CircleAlert, tone: 'text-status-warning' },
};

/**
 * Definições › Estado da configuração (docs/DECISOES.md, "Verificação da
 * configuração"): each thing the agents depend on, with how to fix what fails.
 */
export default function Health({ checks }: { checks: Check[] }) {
    const [busy, setBusy] = useState(false);
    const failing = checks.filter((check) => check.status === 'needs_setup').length;

    return (
        <SettingsLayout>
            <Head title="Estado da configuração" />
            <PageHeader
                title="Estado da configuração"
                description={
                    failing === 0
                        ? 'Tudo o que os agentes precisam para trabalhar, testado a sério: o modelo de IA, a internet, as caixas de correio, o ERP e a fila.'
                        : `${failing === 1 ? 'Uma coisa precisa' : `${failing} coisas precisam`} de configuração. Sem ela os agentes falham em silêncio.`
                }
                actions={
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            router.post(
                                '/settings/health',
                                {},
                                { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
                            )
                        }
                    >
                        {busy ? <Loader2 className="animate-spin" /> : <RefreshCw />}
                        {busy ? 'A verificar…' : 'Verificar de novo'}
                    </Button>
                }
            />

            <ul className="flex flex-col divide-y">
                {checks.map((check) => {
                    const state = states[check.status];

                    return (
                        <li key={check.key} className="flex items-start gap-3 py-4">
                            <state.icon className={cn('mt-0.5 size-5 shrink-0', state.tone)} aria-hidden="true" />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <span className="text-sm font-medium">{check.label}</span>
                                    <span className={cn('text-xs', state.tone)}>{state.label}</span>
                                </div>
                                <p className="text-sm text-muted-foreground">{check.detail}</p>
                                {check.status === 'needs_setup' && check.fix && (
                                    <p className="mt-1 text-sm">
                                        {check.fix}{' '}
                                        {check.href && (
                                            <Link href={check.href} className="inline-flex items-center gap-0.5 text-primary hover:underline">
                                                Abrir
                                                <ArrowRight className="size-3" />
                                            </Link>
                                        )}
                                    </p>
                                )}
                            </div>
                            {check.checked_at && (
                                <span className="shrink-0 text-xs text-muted-foreground" title={dateTime(check.checked_at)}>
                                    {ago(check.checked_at)}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </SettingsLayout>
    );
}
