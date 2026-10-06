import { Head, Link, router } from '@inertiajs/react';
import { Workflow } from 'lucide-react';
import { useState } from 'react';

import { ListHeader, ListPanel } from '@/Components/Blocks';
import { PageHeader } from '@/Components/PageHeader';
import { NativeSelect } from '@/Components/ui/native-select';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { plural } from '@/lib/format';

interface Rule {
    category: string;
    label: string;
    custom: boolean;
    agent_id: number | null;
    fallback_user_id: number | null;
    default_agent: string | null;
    handled_by: string | null;
    source: 'workflow' | 'rule' | 'default' | 'none';
    workflow: { id: number; name: string } | null;
}

interface Props {
    rules: Rule[];
    agents: { id: number; name: string }[];
    people: { id: number; name: string }[];
}

/** The rule's choice as one value of the select: the default, an agent, or nobody. */
const modeOf = (rule: Rule) => (!rule.custom ? 'default' : rule.agent_id ? `agent:${rule.agent_id}` : 'none');

/**
 * Definições › Regras de email: which agent each kind of email goes to after
 * triage, and who takes over when the agent cannot. An active flow for the
 * kind of email decides its agent (docs/DECISOES.md, "Fluxos de trabalho").
 */
export default function EmailRules({ rules, agents, people }: Props) {
    const [busy, setBusy] = useState<string | null>(null);

    const save = (rule: Rule, mode: string, fallback: string) => {
        setBusy(rule.category);
        router.put(
            `/settings/email-rules/${rule.category}`,
            {
                mode: mode.startsWith('agent:') ? 'agent' : mode,
                agent_id: mode.startsWith('agent:') ? Number(mode.slice(6)) : null,
                fallback_user_id: fallback ? Number(fallback) : null,
            },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <SettingsLayout>
            <Head title="Regras de email" />
            <PageHeader
                title="Regras de email"
                description="A quem a Triagem passa cada tipo de email. O agente abre a tarefa e começa sozinho; se houver um fluxo activo para o tipo, é o fluxo que diz quem trata e os passos."
            />

            <div>
                <ListHeader
                    title="Tipo de email"
                    meta={<span className="w-56">Quem trata</span>}
                    trailing={<span className="w-52">Se o agente não conseguir</span>}
                />
                <ListPanel>
                    {rules.map((rule) => (
                        <div key={rule.category} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-3">
                            <div className="min-w-0 flex-1">
                                <div className="text-sm font-medium">{rule.label}</div>
                                <div className="truncate text-xs text-muted-foreground">
                                    {rule.workflow ? (
                                        <Link href={`/workflows?w=${rule.workflow.id}`} className="inline-flex items-center gap-1 hover:underline">
                                            <Workflow className="size-3" />
                                            Fluxo «{rule.workflow.name}» com o {rule.handled_by}
                                        </Link>
                                    ) : rule.handled_by ? (
                                        `Vai ao ${rule.handled_by}`
                                    ) : (
                                        'Fica com a Triagem e a pessoa notificada'
                                    )}
                                </div>
                            </div>
                            <div className="sm:w-56">
                                <NativeSelect
                                    aria-label={`Quem trata «${rule.label}»`}
                                    value={modeOf(rule)}
                                    disabled={busy === rule.category || rule.workflow !== null}
                                    onChange={(event) => save(rule, event.target.value, rule.fallback_user_id ? String(rule.fallback_user_id) : '')}
                                >
                                    <option value="default">Por omissão{rule.default_agent ? ` (${rule.default_agent})` : ' (ninguém)'}</option>
                                    {agents.map((agent) => (
                                        <option key={agent.id} value={`agent:${agent.id}`}>
                                            {agent.name}
                                        </option>
                                    ))}
                                    <option value="none">Ninguém: fica com a Triagem</option>
                                </NativeSelect>
                            </div>
                            <div className="sm:w-52">
                                <NativeSelect
                                    aria-label={`Pessoa de recurso para «${rule.label}»`}
                                    value={rule.fallback_user_id ?? ''}
                                    disabled={busy === rule.category}
                                    onChange={(event) => save(rule, modeOf(rule), event.target.value)}
                                >
                                    <option value="">A chefia do agente</option>
                                    {people.map((person) => (
                                        <option key={person.id} value={person.id}>
                                            {person.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        </div>
                    ))}
                </ListPanel>
                <p className="mt-2 text-xs text-muted-foreground">
                    {plural(rules.length, 'tipo', 'tipos')} de email, {rules.filter((rule) => rule.workflow).length} com fluxo activo.{' '}
                    <Link href="/workflows" className="text-primary hover:underline">
                        Ver o mapa dos fluxos
                    </Link>
                </p>
                {rules.some((rule) => rule.workflow) && (
                    <p className="mt-1 text-xs text-muted-foreground">Num tipo com fluxo activo, o agente muda-se no próprio fluxo.</p>
                )}
            </div>
        </SettingsLayout>
    );
}
