import { Head, Link } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { EntityRow, ListHeader, ListPanel, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { period as periodLabel, plural, usd } from '@/lib/format';
import { cn } from '@/lib/utils';

interface AgentUsage {
    id: number;
    name: string;
    avatar_url: string | null;
    runs: number;
    spent: number;
    cap: number | null;
}

interface Props {
    period: string;
    day: number;
    days: number;
    spent: number;
    cap: number | null;
    extra: number;
    agent_cap: number | null;
    run_cap: number | null;
    agents: AgentUsage[];
    months: { period: string; runs: number; spent: number }[];
}

/** Share of a cap, coloured as the budget guard acts: a warning at 80%, a stop at 100%. */
function Meter({ value, cap, className }: { value: number; cap: number; className?: string }) {
    const share = cap > 0 ? value / cap : 0;

    return (
        <div
            role="meter"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(share * 100)}
            className={cn('h-2 overflow-hidden rounded-full bg-muted', className)}
        >
            <div
                className={cn('h-full rounded-full', share >= 1 ? 'bg-status-danger' : share >= 0.8 ? 'bg-status-warning' : 'bg-primary')}
                style={{ width: `${Math.min(100, Math.max(share > 0 ? 2 : 0, share * 100))}%` }}
            />
        </div>
    );
}

const percent = (value: number, cap: number) => `${Math.round((value / cap) * 100)}%`;
const capitalised = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/** Definições › Consumo de IA: this month's spend against the caps Rethink sets, per agent and over six months. */
export default function Usage({ period, day, days, spent, cap, extra, agent_cap, run_cap, agents, months }: Props) {
    const projected = day > 0 ? (spent / day) * days : spent;
    const busiest = Math.max(...months.map((month) => month.spent), 0);

    return (
        <SettingsLayout>
            <Head title="Consumo de IA" />
            <PageHeader
                title="Consumo de IA"
                description="Quanto os agentes gastaram em modelos de IA. Os tectos são definidos pela Rethink: aos 80% chega um aviso e, aos 100%, os agentes param até alguém aprovar mais."
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
                        <div>
                            <p className="text-sm text-muted-foreground">Gasto em {periodLabel(period)}</p>
                            <p className="text-3xl font-semibold tracking-tight tabular-nums">{usd(spent)}</p>
                        </div>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {cap !== null ? (
                                <>
                                    de <span className="font-medium text-foreground">{usd(cap)}</span> · {percent(spent, cap)}
                                </>
                            ) : (
                                'Sem tecto mensal'
                            )}
                        </p>
                    </div>
                    {cap !== null && <Meter value={spent} cap={cap} />}
                    <p className="text-sm text-muted-foreground">
                        {spent > 0 ? (
                            <>
                                Ao ritmo actual, o mês fecha perto de <span className="font-medium text-foreground">{usd(projected)}</span>
                                {cap !== null && projected > cap ? ', acima do tecto.' : '.'}{' '}
                            </>
                        ) : (
                            'Ainda sem gastos este mês. '
                        )}
                        {days - day > 0 ? `Faltam ${plural(days - day, 'dia', 'dias')}.` : 'Último dia do mês.'}
                        {extra > 0 && ` Inclui uma excepção de ${usd(extra)} aprovada este mês.`}
                    </p>
                </div>

                <div className="rounded-xl border bg-card px-5 py-3">
                    <Property label="Organização">{cap !== null ? `${usd(cap - extra)} por mês` : 'Sem tecto'}</Property>
                    <Property label="Cada agente">{agent_cap !== null ? `${usd(agent_cap)} por mês` : 'Sem tecto'}</Property>
                    <Property label="Cada execução">{run_cap !== null ? usd(run_cap) : 'Sem tecto'}</Property>
                    <p className="pt-2 text-xs text-muted-foreground">
                        Os tectos mudam-se com a Rethink. Quando um agente chega ao tecto, o pedido de excepção aparece em{' '}
                        <Link href="/approvals" className="font-medium text-foreground underline-offset-4 hover:underline">
                            Aprovações
                        </Link>
                        .
                    </p>
                </div>
            </div>

            <Section title="Por agente, este mês">
                {agents.length === 0 ? (
                    <EmptyState icon={ChartColumn} title="Sem gastos este mês" description="Quando um agente trabalhar, o que gastou aparece aqui." />
                ) : (
                    <ListPanel>
                        <ListHeader
                            leading={<span className="w-7" />}
                            title="Agente"
                            meta={<span className="w-24 text-right">Execuções</span>}
                            trailing={<span className="w-44 text-right">Gasto{agent_cap !== null ? ' e tecto' : ''}</span>}
                        />
                        {agents.map((agent) => (
                            <EntityRow
                                key={agent.id}
                                leading={<AgentAvatar name={agent.name} url={agent.avatar_url} className="size-7 rounded-lg text-[10px]" />}
                                title={agent.name}
                                subtitle={<span className="sm:hidden">{plural(agent.runs, 'execução', 'execuções')}</span>}
                                meta={<span className="w-24 text-right tabular-nums">{agent.runs}</span>}
                                trailing={
                                    <div className="flex w-28 flex-col items-end gap-1 sm:w-44">
                                        <span className="text-sm tabular-nums">
                                            {usd(agent.spent)}
                                            {agent.cap !== null && (
                                                <span className="text-xs text-muted-foreground"> · {percent(agent.spent, agent.cap)}</span>
                                            )}
                                        </span>
                                        {agent.cap !== null && <Meter value={agent.spent} cap={agent.cap} className="h-1.5 w-full" />}
                                    </div>
                                }
                            />
                        ))}
                    </ListPanel>
                )}
            </Section>

            <Section title="Últimos seis meses">
                <ListPanel>
                    <ListHeader
                        title="Mês"
                        meta={<span className="w-24 text-right">Execuções</span>}
                        trailing={<span className="w-44 text-right">Gasto</span>}
                    />
                    {[...months].reverse().map((month) => (
                        <EntityRow
                            key={month.period}
                            title={capitalised(periodLabel(month.period))}
                            subtitle={<span className="sm:hidden">{plural(month.runs, 'execução', 'execuções')}</span>}
                            meta={<span className="w-24 text-right tabular-nums">{month.runs}</span>}
                            trailing={
                                <div className="flex w-28 flex-col items-end gap-1 sm:w-44">
                                    <span className="text-sm tabular-nums">{usd(month.spent)}</span>
                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-muted-foreground/40"
                                            style={{
                                                width: `${busiest > 0 ? Math.max(month.spent > 0 ? 2 : 0, (month.spent / busiest) * 100) : 0}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            }
                        />
                    ))}
                </ListPanel>
            </Section>
        </SettingsLayout>
    );
}
