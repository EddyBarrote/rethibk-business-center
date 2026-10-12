import { Head, Link, router } from '@inertiajs/react';
import { Brain, History, ShieldAlert, Sparkles } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ConfirmDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { type MemoryEntry, MemoryEntries, type MemorySectionOption } from '@/Components/memory/MemoryEntries';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Switch } from '@/Components/ui/switch';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type PrefKey = 'search_chats' | 'generate' | 'sensitive';

interface AgentOption {
    id: number;
    name: string;
    title: string | null;
    avatar_url: string | null;
    count: number;
    updated_at: string | null;
}

interface Props {
    prefs: Record<PrefKey, boolean>;
    sections: MemorySectionOption[];
    agents: AgentOption[];
    entries: MemoryEntry[];
}

const switches: { key: PrefKey; label: string; description: string; icon: typeof Brain }[] = [
    {
        key: 'search_chats',
        label: 'Procurar nas conversas anteriores',
        description: 'O agente pode procurar nas suas conversas e tarefas anteriores com ele quando fala de algo que já trataram.',
        icon: History,
    },
    {
        key: 'generate',
        label: 'Gerar memória a partir das conversas',
        description:
            'O agente guarda o que aprende consigo e usa-o da próxima vez. Desligado, deixa de a actualizar e de a usar; o que já lá está fica guardado.',
        icon: Sparkles,
    },
    {
        key: 'sensitive',
        label: 'Incluir temas sensíveis na memória',
        description: 'Saúde, religião, política, vida familiar, dinheiro pessoal. Desligado, o agente não os guarda mesmo que fale deles.',
        icon: ShieldAlert,
    },
];

/** Definições › Memória › A minha memória: the switches, and what each agent remembers of the person (12.10.2026). */
export default function MemorySettings({ prefs, sections, agents, entries }: Props) {
    const [state, setState] = useState(prefs);
    const [agentId, setAgentId] = useState<number | null>(agents[0]?.id ?? null);
    const [forgetting, setForgetting] = useState(false);
    const agent = agents.find((a) => a.id === agentId) ?? agents[0] ?? null;
    const shown = entries.filter((e) => e.agent_id === agent?.id);

    const flip = (key: PrefKey, enabled: boolean) => {
        setState((all) => ({ ...all, [key]: enabled }));
        router.put('/settings/memory/preferences', { key, enabled }, { preserveScroll: true, preserveState: true });
    };

    return (
        <SettingsLayout>
            <Head title="A minha memória" />
            <PageHeader
                title="A minha memória"
                description="O que os agentes lembram de si, e como o usam. Só a sua memória com cada agente: os colegas não a vêem."
            />

            <div className="overflow-hidden rounded-xl border bg-card">
                <ul className="divide-y">
                    {switches.map((item) => (
                        <li key={item.key}>
                            <label htmlFor={`memory-${item.key}`} className="flex cursor-pointer items-start gap-3 px-5 py-4">
                                <span className="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <item.icon className="size-4" />
                                </span>
                                <span className="min-w-0 flex-1 space-y-0.5">
                                    <span className="block text-sm font-medium">{item.label}</span>
                                    <span className="block text-sm text-muted-foreground">{item.description}</span>
                                </span>
                                <Switch
                                    id={`memory-${item.key}`}
                                    checked={state[item.key]}
                                    onCheckedChange={(on) => flip(item.key, on)}
                                    className="mt-1"
                                />
                            </label>
                        </li>
                    ))}
                </ul>
            </div>

            {agents.length === 0 ? (
                <EmptyState
                    icon={Brain}
                    title="Os agentes ainda não guardaram nada de si"
                    description="Ao fim de cada tarefa, a cada 30 mensagens de uma conversa e todas as noites, o agente arruma aqui o que aprendeu consigo, em entradas com nome."
                />
            ) : (
                <section className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Agente">
                            {agents.map((a) => (
                                <button
                                    key={a.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={a.id === agent?.id}
                                    onClick={() => setAgentId(a.id)}
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
                        {agent && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="self-start text-muted-foreground sm:self-auto"
                                onClick={() => setForgetting(true)}
                            >
                                Esquecer tudo
                            </Button>
                        )}
                    </div>

                    {agent && (
                        <>
                            <p className="text-xs text-muted-foreground">
                                {plural(agent.count, 'entrada', 'entradas')} na memória de{' '}
                                <Link href={`/agents/${agent.id}`} className="font-medium text-foreground hover:underline">
                                    {agent.name}
                                </Link>
                                {agent.updated_at && `, actualizada ${ago(agent.updated_at)}`}. «Sobre si» vai sempre inteiro para o agente; das
                                outras secções ele vê o título e a descrição e abre a entrada quando precisa.
                                {!state.generate && ' A memória está desligada: o agente não a usa nem a actualiza.'}
                            </p>
                            <div className={cn(!state.generate && 'opacity-60')}>
                                <MemoryEntries entries={shown} sections={sections} url={(e) => `/settings/memory/${e.id}`} />
                            </div>
                        </>
                    )}
                </section>
            )}

            <p className="text-xs text-muted-foreground">
                O que vale para toda a empresa (clientes, regras, preços) vive na{' '}
                <Link href="/knowledge/company" className="underline-offset-2 hover:underline">
                    Memória da empresa
                </Link>
                , curada por pessoas.
            </p>

            {agent && (
                <ConfirmDialog
                    open={forgetting}
                    onOpenChange={setForgetting}
                    title={`${agent.name} esquece tudo o que sabe de si?`}
                    description={`Apaga as ${plural(agent.count, 'entrada', 'entradas')} desta memória. O agente volta a aprender consigo a partir da próxima conversa, se a memória estiver ligada.`}
                    confirmLabel="Esquecer tudo"
                    destructive
                    onConfirm={() =>
                        router.delete(`/settings/memory/agents/${agent.id}`, { preserveScroll: true, onFinish: () => setForgetting(false) })
                    }
                />
            )}
        </SettingsLayout>
    );
}
