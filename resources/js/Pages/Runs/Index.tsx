import { Head, router } from '@inertiajs/react';
import { Activity, MessagesSquare } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { EntityRow, ListHeader, ListPanel } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { useToolNames } from '@/hooks/useToolNames';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, duration, plural, runTitle, usd } from '@/lib/format';
import type { Paginated, RunSummary } from '@/types';

const statuses = [
    ['', 'Todas'],
    ['running', 'A correr'],
    ['awaiting_approval', 'À espera de aprovação'],
    ['completed', 'Concluídas'],
    ['failed', 'Falhadas'],
];

/** Where a run came from. "Trabalho" is everything but conversations, which are grouped by conversation. */
const origins = [
    ['', 'Trabalho'],
    ['rotina', 'Rotinas'],
    ['tarefa', 'Tarefas'],
    ['email', 'Email'],
    ['conversa', 'Conversas'],
] as const;

interface ConversationGroup {
    task_id: number;
    agent: string | null;
    person: string | null;
    turns: number;
    cost_usd: number;
    last_status: RunSummary['status'] | null;
    last_status_label: string | null;
    last_at: string | null;
}

export default function RunsIndex({
    runs,
    conversations,
    filters,
}: {
    runs: Paginated<RunSummary> | null;
    conversations: Paginated<ConversationGroup> | null;
    filters: { status: string | null; origin: string | null };
}) {
    const toolNames = useToolNames();
    const visit = (next: { status?: string | null; origin?: string | null }) => {
        const params = { status: filters.status, origin: filters.origin, ...next };
        router.get('/runs', Object.fromEntries(Object.entries(params).filter(([, value]) => value)), { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Execuções" />
            <PageHeader
                title="Execuções"
                description="O trabalho dos agentes, com custo e resultado. As conversas com pessoas ficam agrupadas em Conversas."
            />

            <div className="flex flex-col gap-3">
                <Tabs value={filters.status ?? ''} onValueChange={(status) => visit({ status })}>
                    <TabsList variant="line" className="scroll-fade w-full justify-start overflow-x-auto border-b pb-1">
                        {statuses.map(([value, label]) => (
                            <TabsTrigger key={value} value={value} className="flex-none">
                                {label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
                <div className="scroll-fade flex gap-1 overflow-x-auto" role="group" aria-label="Origem">
                    {origins.map(([value, label]) => (
                        <Button
                            key={value}
                            size="xs"
                            variant={(filters.origin ?? '') === value ? 'secondary' : 'ghost'}
                            className="flex-none"
                            onClick={() => visit({ origin: value })}
                            aria-pressed={(filters.origin ?? '') === value}
                        >
                            {label}
                        </Button>
                    ))}
                </div>
            </div>

            {conversations ? (
                conversations.data.length === 0 ? (
                    <EmptyState
                        icon={MessagesSquare}
                        title="Sem conversas"
                        description="Quando alguém escrever a um agente, a conversa aparece aqui, com quantas mensagens teve."
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        <ListPanel>
                            <ListHeader
                                leading={<span className="w-7" />}
                                title="Conversa"
                                meta={
                                    <>
                                        <span className="w-20 text-right">Custo</span>
                                        <span className="w-20 text-right">Última</span>
                                    </>
                                }
                                trailing={<span className="w-44 text-right">Última resposta</span>}
                            />
                            {conversations.data.map((group) => (
                                <EntityRow
                                    key={group.task_id}
                                    href={`/tasks/${group.task_id}`}
                                    leading={<AgentAvatar name={group.agent ?? 'Agente'} />}
                                    title={`Conversa com ${group.agent ?? 'agente'}`}
                                    subtitle={[group.person && `com ${group.person}`, plural(group.turns, 'mensagem', 'mensagens')]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    meta={
                                        <>
                                            <span className="w-20 text-right tabular-nums">{usd(group.cost_usd)}</span>
                                            <span className="w-20 text-right" title={dateTime(group.last_at)}>
                                                {ago(group.last_at)}
                                            </span>
                                        </>
                                    }
                                    trailing={
                                        group.last_status && (
                                            <span className="flex justify-end sm:w-44">
                                                <RunStatusBadge status={group.last_status} label={group.last_status_label ?? ''} />
                                            </span>
                                        )
                                    }
                                />
                            ))}
                        </ListPanel>
                        <Pagination page={conversations} noun={['conversa', 'conversas']} />
                    </div>
                )
            ) : (
                runs &&
                (runs.data.length === 0 ? (
                    <EmptyState
                        icon={Activity}
                        title="Sem execuções"
                        description={
                            filters.status
                                ? 'Nenhuma execução com este estado. Escolha outro separador.'
                                : 'Abra um agente e faça-lhe um pedido; as execuções aparecem aqui assim que começarem.'
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        <ListPanel>
                            <ListHeader
                                leading={
                                    <div className="flex items-center gap-3">
                                        <span className="w-10">#</span>
                                        <span className="w-7" />
                                    </div>
                                }
                                title="Pedido"
                                meta={
                                    <>
                                        <span className="hidden w-16 text-right lg:block">Duração</span>
                                        <span className="w-20 text-right">Custo</span>
                                        <span className="w-20 text-right">Quando</span>
                                    </>
                                }
                                trailing={<span className="w-44 text-right">Estado</span>}
                            />
                            {runs.data.map((run) => (
                                <EntityRow
                                    key={run.id}
                                    href={`/runs/${run.id}`}
                                    leading={
                                        <div className="flex items-center gap-3">
                                            <span className="hidden w-10 font-mono text-xs text-muted-foreground tabular-nums sm:block">
                                                #{run.id}
                                            </span>
                                            <AgentAvatar name={run.agent.name} />
                                        </div>
                                    }
                                    title={run.title ?? runTitle(run.input, toolNames)}
                                    subtitle={`${run.agent.name} · ${run.trigger_label}${run.requested_by ? ` · ${run.requested_by}` : ''}`}
                                    meta={
                                        <>
                                            <span className="hidden w-16 text-right tabular-nums lg:block">{duration(run.duration_ms)}</span>
                                            <span className="w-20 text-right tabular-nums">{usd(run.cost_usd)}</span>
                                            <span className="w-20 text-right" title={dateTime(run.created_at)}>
                                                {ago(run.created_at)}
                                            </span>
                                        </>
                                    }
                                    trailing={
                                        <span className="flex justify-end sm:w-44">
                                            <RunStatusBadge status={run.status} label={run.status_label} />
                                        </span>
                                    }
                                />
                            ))}
                        </ListPanel>
                        <Pagination page={runs} noun={['execução', 'execuções']} />
                    </div>
                ))
            )}
        </AppLayout>
    );
}
