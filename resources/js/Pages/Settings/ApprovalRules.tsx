import { Head, router, useForm } from '@inertiajs/react';
import { Plus, ShieldQuestion, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ListHeader, ListPanel } from '@/Components/Blocks';
import { FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { NativeSelect } from '@/Components/ui/native-select';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime } from '@/lib/format';

interface Rule {
    id: number;
    agent: string | null;
    capability_key: string;
    capability: string;
    effect: string;
    created_by: string | null;
    from_approval: boolean;
    updated_at: string;
}

interface Props {
    rules: Rule[];
    agents: { id: number; name: string }[];
    capabilities: { key: string; name: string }[];
    effects: { value: string; label: string }[];
}

/**
 * Definições › Regras de aprovação (docs/DECISOES.md, "Regras de aprovação"):
 * on top of each agent's level, an action can always pass, always ask, or
 * never happen. «Permitir sempre» on an approval leaves a rule here.
 */
export default function ApprovalRules({ rules, agents, capabilities, effects }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ agent_id: '', capability_key: '', effect: 'ask' });

    const add = () =>
        form.post('/settings/approval-rules', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });

    return (
        <SettingsLayout>
            <Head title="Regras de aprovação" />
            <PageHeader
                title="Regras de aprovação"
                description="Por cima do nível de cada agente: acções que passam sem perguntar, que perguntam sempre, ou que nunca se fazem. O tecto absoluto (pagamentos, emails para fora, contratos) decide sempre uma pessoa, com ou sem regra."
                actions={
                    <Button size="sm" onClick={() => setAdding(true)}>
                        <Plus />
                        Nova regra
                    </Button>
                }
            />

            {rules.length === 0 ? (
                <EmptyState
                    icon={ShieldQuestion}
                    title="Ainda não há regras"
                    description="Ao aprovar uma acção, «Permitir sempre» deixa aqui uma regra para esse agente. Também pode criar uma à mão."
                />
            ) : (
                <div>
                    <ListHeader title="Acção" meta={<span className="w-44">Agente</span>} trailing={<span className="w-52">Regra</span>} />
                    <ListPanel>
                        {rules.map((rule) => (
                            <div key={rule.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-3">
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium" title={rule.capability_key}>
                                        {rule.capability}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground" title={dateTime(rule.updated_at)}>
                                        {rule.from_approval ? 'Vem de «Permitir sempre»' : 'Criada à mão'}
                                        {rule.created_by && ` por ${rule.created_by}`} · {ago(rule.updated_at)}
                                    </div>
                                </div>
                                <div className="truncate text-sm sm:w-44">{rule.agent ?? 'Todos os agentes'}</div>
                                <div className="flex items-center gap-1 sm:w-52">
                                    <NativeSelect
                                        aria-label={`Regra para «${rule.capability}»`}
                                        value={rule.effect}
                                        onChange={(event) =>
                                            router.put(
                                                `/settings/approval-rules/${rule.id}`,
                                                { effect: event.target.value },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {effects.map((effect) => (
                                            <option key={effect.value} value={effect.value}>
                                                {effect.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        className="shrink-0 text-muted-foreground"
                                        aria-label="Retirar a regra"
                                        title="Retirar: volta a valer o nível do agente"
                                        onClick={() => router.delete(`/settings/approval-rules/${rule.id}`, { preserveScroll: true })}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </ListPanel>
                </div>
            )}

            <FormDialog
                open={adding}
                onOpenChange={setAdding}
                title="Nova regra"
                description="A regra de um agente vale antes da regra para todos."
                submitLabel="Guardar regra"
                processing={form.processing}
                disabled={form.data.capability_key === ''}
                onSubmit={(event) => {
                    event.preventDefault();
                    add();
                }}
            >
                <Field id="rule-capability" label="Acção" error={form.errors.capability_key}>
                    <NativeSelect
                        id="rule-capability"
                        value={form.data.capability_key}
                        onChange={(event) => form.setData('capability_key', event.target.value)}
                    >
                        <option value="">Escolher…</option>
                        {capabilities.map((capability) => (
                            <option key={capability.key} value={capability.key}>
                                {capability.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="rule-agent" label="Agente" error={form.errors.agent_id}>
                    <NativeSelect id="rule-agent" value={form.data.agent_id} onChange={(event) => form.setData('agent_id', event.target.value)}>
                        <option value="">Todos os agentes</option>
                        {agents.map((agent) => (
                            <option key={agent.id} value={String(agent.id)}>
                                {agent.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="rule-effect" label="Regra" error={form.errors.effect}>
                    <NativeSelect id="rule-effect" value={form.data.effect} onChange={(event) => form.setData('effect', event.target.value)}>
                        {effects.map((effect) => (
                            <option key={effect.value} value={effect.value}>
                                {effect.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            </FormDialog>
        </SettingsLayout>
    );
}
