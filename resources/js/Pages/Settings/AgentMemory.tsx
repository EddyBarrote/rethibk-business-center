import { Head, Link, router } from '@inertiajs/react';
import { Brain } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { EmptyState } from '@/Components/EmptyState';
import { type MemoryEntry, MemoryEntries, type MemorySectionOption } from '@/Components/memory/MemoryEntries';
import { PageHeader } from '@/Components/PageHeader';
import { NativeSelect } from '@/Components/ui/native-select';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { cn } from '@/lib/utils';

interface AgentOption {
    id: number;
    name: string;
    avatar_url: string | null;
    count: number;
}

interface Props {
    agents: AgentOption[];
    agent_id: number | null;
    sections: MemorySectionOption[];
    entries: MemoryEntry[];
}

/** Definições › Memória dos agentes: what each agent remembers of each person, for the administrators to correct (12.10.2026). */
export default function AgentMemory({ agents, agent_id, sections, entries }: Props) {
    const [person, setPerson] = useState<string>('all');
    const agent = agents.find((a) => a.id === agent_id) ?? null;
    const people = [...new Map(entries.filter((e) => e.person).map((e) => [e.person!.id, e.person!.name])).entries()].sort((a, b) =>
        a[1].localeCompare(b[1]),
    );
    const shown = entries.filter((e) => person === 'all' || (person === 'agent' ? e.person === null : String(e.person?.id) === person));

    const choose = (id: number) => {
        setPerson('all');
        router.get('/settings/agent-memory', { agente: id }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <SettingsLayout>
            <Head title="Memória dos agentes" />
            <PageHeader
                title="Memória dos agentes"
                description={
                    <>
                        O que cada agente lembra de cada pessoa, em entradas por secção, para corrigir ou apagar. Cada pessoa vê a sua em{' '}
                        <Link href="/settings/memory" className="underline-offset-2 hover:underline">
                            A minha memória
                        </Link>
                        .
                    </>
                }
            />

            {agents.length > 0 && (
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Agente">
                    {agents.map((a) => (
                        <button
                            key={a.id}
                            type="button"
                            role="tab"
                            aria-selected={a.id === agent?.id}
                            onClick={() => choose(a.id)}
                            className={cn(
                                'flex shrink-0 items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm transition-colors hover:bg-accent/60',
                                a.id === agent?.id ? 'border-foreground/20 bg-accent font-medium' : 'bg-card text-foreground/80',
                            )}
                        >
                            <AgentAvatar name={a.name} url={a.avatar_url} className="size-6" />
                            {a.name}
                            <span className="font-mono text-xs text-muted-foreground tabular-nums">{a.count}</span>
                        </button>
                    ))}
                </div>
            )}

            {entries.length === 0 ? (
                <EmptyState
                    icon={Brain}
                    title={agent ? `${agent.name} ainda não guardou nada` : 'Ainda sem agentes'}
                    description="Ao fim de cada tarefa, a cada 30 mensagens de uma conversa e todas as noites, o agente arruma aqui o que aprendeu, em entradas com nome."
                />
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <p className="flex-1 text-xs text-muted-foreground">
                            Cada pessoa tem a sua memória com o agente. As entradas partilhadas também vão para a base de conhecimento (pasta Memória
                            dos agentes), e é assim que servem os outros; as outras nunca saem dali.
                        </p>
                        <NativeSelect aria-label="Memória de quem" className="sm:w-64" value={person} onChange={(e) => setPerson(e.target.value)}>
                            <option value="all">Todas as pessoas</option>
                            {people.map(([id, name]) => (
                                <option key={id} value={String(id)}>
                                    {name}
                                </option>
                            ))}
                            {entries.some((e) => e.person === null) && <option value="agent">Só do agente (sem pessoa)</option>}
                        </NativeSelect>
                    </div>
                    <MemoryEntries
                        entries={shown}
                        sections={sections}
                        url={(e) => `/agents/${e.agent_id}/memories/${e.id}`}
                        showPerson={person === 'all'}
                    />
                </div>
            )}
        </SettingsLayout>
    );
}
