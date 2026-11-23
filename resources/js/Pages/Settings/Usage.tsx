import { Head, Link, router } from '@inertiajs/react';
import { ChartColumn, FileSearch, Lock } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { EntityRow, ListHeader, ListPanel, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { NativeSelect } from '@/Components/ui/native-select';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { duration, errorRate, period as periodLabel, plural, usd } from '@/lib/format';
import { cn } from '@/lib/utils';

interface AgentUsage {
    id: number;
    name: string;
    avatar_url: string | null;
    runs: number;
    /** Failed runs, and the ones with an outcome (completed or failed) the error rate is out of. */
    failed: number;
    finished: number;
    /** failed ÷ finished, 0 to 1; null when nothing finished. */
    error_rate: number | null;
    /** Median and 90th percentile of how long finished runs took. */
    p50_ms: number | null;
    p90_ms: number | null;
    spent: number;
    /** Only for the current month, the one the cap applies to. */
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
    /** The period of the per-agent table: a month (2026-11) or the last 30 days ("30d"). */
    selected: string;
    periods: string[];
    agents: AgentUsage[];
    /** Spent by agents this person may not see (confidential ones), counted in the totals. */
    hidden_spent: number;
    months: { period: string; runs: number; spent: number }[];
    other: { purpose: string; label: string; calls: number; spent: number }[];
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
const periodName = (value: string) => (value === '30d' ? 'últimos 30 dias' : periodLabel(value));
/** The agent's run log, every origin, as its page opens it. */
const runLog = (agentId: number) => `/settings/runs?agent=${agentId}&origin=todas`;

/** Definições › Consumo de IA: this month's spend against the caps Rethink sets, per agent and over six months. */
export default function Usage({
    period,
    day,
    days,
    spent,
    cap,
    extra,
    agent_cap,
    run_cap,
    selected,
    periods,
    agents,
    hidden_spent,
    months,
    other,
}: Props) {
    const projected = day > 0 ? (spent / day) * days : spent;
    const busiest = Math.max(...months.map((month) => month.spent), 0);
    const showCap = agent_cap !== null && selected === period;
    const choose = (value: string) =>
        router.get('/settings/usage', value === period ? {} : { period: value }, {
            preserveScroll: true,
            preserveState: true,
            only: ['selected', 'agents', 'hidden_spent', 'other'],
        });

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

            <Section
                title={selected === period ? 'Por agente, este mês' : `Por agente, ${periodName(selected)}`}
                action={
                    <NativeSelect aria-label="Período" className="w-48" value={selected} onChange={(event) => choose(event.target.value)}>
                        {periods.map((value) => (
                            <option key={value} value={value}>
                                {value === period ? 'Este mês' : capitalised(periodName(value))}
                            </option>
                        ))}
                    </NativeSelect>
                }
            >
                {agents.length === 0 && other.length === 0 ? (
                    <EmptyState
                        icon={ChartColumn}
                        title={selected === period ? 'Sem gastos este mês' : 'Sem execuções neste período'}
                        description="Quando um agente trabalhar, o que fez e gastou aparece aqui."
                    />
                ) : (
                    <ListPanel>
                        <ListHeader
                            leading={<span className="w-7" />}
                            title="Agente"
                            meta={
                                <>
                                    <span className="w-20 text-right">Execuções</span>
                                    <span className="w-24 text-right" title="Execuções que falharam, em percentagem das que terminaram">
                                        Taxa de erro
                                    </span>
                                    <span
                                        className="hidden w-32 text-right lg:block"
                                        title="Quanto demorou a execução típica (mediana) e 9 em cada 10 (p90)"
                                    >
                                        Duração · p90
                                    </span>
                                </>
                            }
                            trailing={<span className="w-44 text-right">Gasto{showCap ? ' e tecto' : ''}</span>}
                        />
                        {agents.map((agent) => (
                            <EntityRow
                                key={agent.id}
                                href={runLog(agent.id)}
                                leading={<AgentAvatar name={agent.name} url={agent.avatar_url} className="size-7 rounded-lg text-[10px]" />}
                                title={agent.name}
                                subtitle={
                                    <>
                                        <span className="sm:hidden">
                                            {plural(agent.runs, 'execução', 'execuções')} · {errorRate(agent.error_rate)} de erro ·{' '}
                                        </span>
                                        <span className="lg:hidden">
                                            {duration(agent.p50_ms)} típica, p90 {duration(agent.p90_ms)}
                                        </span>
                                    </>
                                }
                                meta={
                                    <>
                                        <span className="w-20 text-right tabular-nums">{agent.runs}</span>
                                        <span
                                            className={cn('w-24 text-right tabular-nums', agent.failed > 0 && 'text-status-danger')}
                                            title={`${plural(agent.failed, 'falhou', 'falharam')} de ${plural(agent.finished, 'terminada', 'terminadas')}`}
                                        >
                                            {errorRate(agent.error_rate)}
                                        </span>
                                        <span className="hidden w-32 text-right tabular-nums lg:block">
                                            {duration(agent.p50_ms)} · {duration(agent.p90_ms)}
                                        </span>
                                    </>
                                }
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
                        {other.map((row) => (
                            <EntityRow
                                key={row.purpose}
                                leading={
                                    <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <FileSearch className="size-3.5" />
                                    </span>
                                }
                                title={row.label}
                                subtitle={<span className="sm:hidden">{plural(row.calls, 'pedido', 'pedidos')}</span>}
                                meta={
                                    <>
                                        <span className="w-20 text-right tabular-nums">{row.calls}</span>
                                        <span className="w-24" />
                                        <span className="hidden w-32 lg:block" />
                                    </>
                                }
                                trailing={<span className="w-28 text-right text-sm tabular-nums sm:w-44">{usd(row.spent)}</span>}
                            />
                        ))}
                    </ListPanel>
                )}
                <p className="text-xs text-muted-foreground">
                    A taxa de erro conta as execuções que falharam entre as que terminaram. A duração é o tempo em que o agente esteve a trabalhar,
                    sem contar a espera por aprovações ou respostas: a típica (mediana) e a de 9 em cada 10 (p90). Cada agente abre o seu registo de
                    execuções.
                </p>
                {hidden_spent > 0 && (
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Lock className="size-3.5" />
                        Inclui {usd(hidden_spent)} de agentes que só aparecem a quem tem «Gerir pessoas e papéis».
                    </p>
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
                            href={month.period === period ? '/settings/usage' : `/settings/usage?period=${month.period}`}
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
