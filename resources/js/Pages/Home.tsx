import { Head, Link, router, usePage } from '@inertiajs/react';
import { Inbox } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import type { ApprovalSummary, SharedProps } from '@/types';

import { TaskRow, type TaskSummary } from './Tasks/Index';

interface Colleagues {
    people: { id: number; name: string; role: string }[];
    agents: { id: number; name: string; title: string | null; avatar_url: string | null }[];
}

interface Props {
    waiting: TaskSummary[];
    review: TaskSummary[];
    approvals: (ApprovalSummary & { task_id: number | null })[];
    work: TaskSummary[];
    colleagues: Colleagues;
}

const reloadProps = ['waiting', 'review', 'approvals', 'work', 'auth', 'sidebar_agents'];

export default function Home({ waiting, review, approvals, work, colleagues }: Props) {
    const { auth, tenant } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const needsMe = waiting.length + review.length + approvals.length;

    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunFinished'], () => router.reload({ only: reloadProps }));

    return (
        <AppLayout>
            <Head title="A minha caixa" />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    <PageHeader
                        title={`Olá, ${firstName}`}
                        description={
                            needsMe === 0
                                ? 'Nada precisa de si agora. O seu trabalho e os seus colegas estão abaixo.'
                                : `${needsMe} ${needsMe === 1 ? 'coisa precisa' : 'coisas precisam'} de si.`
                        }
                    />

                    {waiting.length > 0 && (
                        <Section title="À sua espera">
                            <ListPanel>
                                {waiting.map((task) => (
                                    <TaskRow key={task.id} task={task} />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    {approvals.length > 0 && (
                        <Section title="Para aprovar">
                            <ApprovalList>
                                {approvals.map((approval) => (
                                    <ApprovalCard key={approval.id} approval={approval} taskHref={approval.task_id !== null ? `/tasks/${approval.task_id}` : null} />
                                ))}
                            </ApprovalList>
                        </Section>
                    )}

                    {review.length > 0 && (
                        <Section title="Para rever">
                            <ListPanel>
                                {review.map((task) => (
                                    <TaskRow key={task.id} task={task} />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    <Section title="O meu trabalho">
                        {work.length === 0 ? (
                            <EmptyState
                                icon={Inbox}
                                title="Sem tarefas em aberto"
                                description="Peça trabalho a um agente na conversa com ele; a tarefa aparece aqui."
                            />
                        ) : (
                            <ListPanel>
                                {work.map((task) => (
                                    <TaskRow key={task.id} task={task} />
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>

                <aside className="flex flex-col gap-8 self-start">
                    <Section title="Agentes com quem trabalho">
                        {colleagues.agents.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Ainda não tem acesso a nenhum agente. Peça à sua chefia.</p>
                        ) : (
                            <ListPanel>
                                {colleagues.agents.map((agent) => (
                                    <Link
                                        key={agent.id}
                                        href={`/agents/${agent.id}/chat`}
                                        className="flex items-center gap-3 px-3 py-2 transition-colors hover:bg-accent/60"
                                    >
                                        <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-7 rounded-lg text-[10px]" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{agent.name}</span>
                                            {agent.title && <span className="block truncate text-xs text-muted-foreground">{agent.title}</span>}
                                        </span>
                                    </Link>
                                ))}
                            </ListPanel>
                        )}
                    </Section>

                    {colleagues.people.length > 0 && (
                        <Section title="Pessoas">
                            <ListPanel>
                                {colleagues.people.map((person) => (
                                    <div key={person.id} className="flex items-center gap-3 px-3 py-2">
                                        <Monogram name={person.name} className="size-7 text-[10px]" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{person.name}</span>
                                            <span className="block truncate text-xs text-muted-foreground">{person.role}</span>
                                        </span>
                                    </div>
                                ))}
                            </ListPanel>
                        </Section>
                    )}
                </aside>
            </div>
        </AppLayout>
    );
}
