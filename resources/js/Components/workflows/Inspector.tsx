import { Trash2 } from 'lucide-react';

import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { ReadinessBadge } from '@/Components/workflows/Readiness';
import { blocks, type BlockData, fixLabel, type Readiness, type WfNode } from '@/lib/workflows';

/*
 * The settings of the block chosen on the canvas or in the list, and what it
 * will do with what the agents have.
 */

export interface CapabilityOption {
    key: string;
    name: string;
    description: string | null;
    is_mutating: boolean;
    risk: string;
    available: boolean;
}

export interface AgentOption {
    id: number;
    name: string;
    level: string;
    level_label: string;
    active: boolean;
    capabilities: string[];
}

function CapabilitySelect({
    id,
    value,
    agent,
    capabilities,
    onChange,
    optional = true,
}: {
    id: string;
    value: string | undefined;
    agent: AgentOption | undefined;
    capabilities: CapabilityOption[];
    onChange: (value: string | undefined) => void;
    optional?: boolean;
}) {
    const own = capabilities.filter((capability) => agent?.capabilities.includes(capability.key));
    const others = capabilities.filter((capability) => !agent?.capabilities.includes(capability.key));
    const unknown = value && !capabilities.some((capability) => capability.key === value);

    return (
        <NativeSelect id={id} value={value ?? ''} onChange={(event) => onChange(event.target.value || undefined)}>
            {optional && <option value="">Nenhuma: o agente decide como</option>}
            {unknown && <option value={value}>{value} (não existe no catálogo)</option>}
            {agent && own.length > 0 && (
                <optgroup label={`Do ${agent.name}`}>
                    {own.map((capability) => (
                        <option key={capability.key} value={capability.key}>
                            {capability.name}
                            {capability.is_mutating ? ` · ${capability.risk}` : ''}
                        </option>
                    ))}
                </optgroup>
            )}
            {others.length > 0 && (
                <optgroup label={agent ? `O ${agent.name} não tem` : 'Catálogo'}>
                    {others.map((capability) => (
                        <option key={capability.key} value={capability.key}>
                            {capability.name}
                        </option>
                    ))}
                </optgroup>
            )}
        </NativeSelect>
    );
}

export function BlockInspector({
    node,
    readiness,
    agent,
    agents,
    people,
    capabilities,
    skills,
    onChange,
    onDelete,
    readOnly = false,
}: {
    node: WfNode;
    readiness: Readiness | undefined;
    agent: AgentOption | undefined;
    agents: AgentOption[];
    people: { id: number; name: string }[];
    capabilities: CapabilityOption[];
    skills: { key: string; name: string }[];
    onChange: (patch: Partial<BlockData>) => void;
    onDelete: () => void;
    readOnly?: boolean;
}) {
    const meta = blocks[node.type];
    const Icon = meta.icon;
    const id = (field: string) => `block-${node.id}-${field}`;
    const target = node.type === 'handoff' ? agents.find((row) => row.id === node.data.agent_id) : agent;
    const selected = capabilities.find((capability) => capability.key === node.data.capability);

    return (
        <fieldset disabled={readOnly} className="flex flex-col gap-4">
            <div className="flex items-start gap-2.5">
                <span className={`inline-flex size-7 shrink-0 items-center justify-center rounded-lg ${meta.swatch}`}>
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0">
                    <div className="text-sm font-semibold">{meta.label}</div>
                    <p className="text-xs text-muted-foreground">{meta.description}</p>
                </div>
            </div>

            <Field id={id('label')} label={node.type === 'condition' ? 'Pergunta' : node.type === 'trigger' ? 'Nome do gatilho' : 'Nome'}>
                <Input id={id('label')} value={node.data.label ?? ''} maxLength={200} onChange={(event) => onChange({ label: event.target.value })} />
            </Field>

            {node.type === 'handoff' && (
                <Field id={id('agent')} label="Passa ao agente">
                    <NativeSelect
                        id={id('agent')}
                        value={node.data.agent_id ?? ''}
                        onChange={(event) => onChange({ agent_id: event.target.value ? Number(event.target.value) : undefined })}
                    >
                        <option value="">Escolha o agente</option>
                        {agents
                            .filter((row) => row.id !== agent?.id)
                            .map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.name} · {row.level}
                                    {row.active ? '' : ' (suspenso)'}
                                </option>
                            ))}
                    </NativeSelect>
                </Field>
            )}

            {['agent', 'handoff', 'condition', 'approval', 'person'].includes(node.type) && (
                <Field
                    id={id('instruction')}
                    label={node.type === 'condition' ? 'Como decidir (opcional)' : node.type === 'approval' ? 'O que a pessoa revê' : 'O que fazer'}
                    hint={node.type === 'agent' || node.type === 'handoff' ? 'Escrito para o agente: concreto e curto.' : undefined}
                >
                    <Textarea
                        id={id('instruction')}
                        rows={3}
                        value={node.data.instruction ?? ''}
                        maxLength={4000}
                        onChange={(event) => onChange({ instruction: event.target.value })}
                    />
                </Field>
            )}

            {['agent', 'handoff', 'condition'].includes(node.type) && (
                <Field
                    id={id('capability')}
                    label={node.type === 'condition' ? 'Decide com a capacidade' : 'Com que capacidade'}
                    hint={selected?.description ? selected.description : undefined}
                >
                    <CapabilitySelect
                        id={id('capability')}
                        value={node.data.capability}
                        agent={target}
                        capabilities={capabilities}
                        onChange={(value) => onChange({ capability: value })}
                    />
                </Field>
            )}

            {node.type === 'agent' && (
                <Field id={id('skill')} label="Skill a seguir (opcional)">
                    <NativeSelect
                        id={id('skill')}
                        value={node.data.skill ?? ''}
                        onChange={(event) => onChange({ skill: event.target.value || undefined })}
                    >
                        <option value="">Nenhuma</option>
                        {node.data.skill && !skills.some((skill) => skill.key === node.data.skill) && (
                            <option value={node.data.skill}>{node.data.skill} (não existe)</option>
                        )}
                        {skills.map((skill) => (
                            <option key={skill.key} value={skill.key}>
                                {skill.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            )}

            {node.type === 'loop' && (
                <Field id={id('items')} label="Para cada…" hint="O agente lista os itens e a plataforma repete os blocos de dentro para cada um.">
                    <Textarea
                        id={id('items')}
                        rows={2}
                        value={node.data.items ?? ''}
                        maxLength={1000}
                        placeholder="fornecedor a consultar para os materiais pedidos"
                        onChange={(event) => onChange({ items: event.target.value })}
                    />
                </Field>
            )}

            {node.type === 'repeat' && (
                <Field id={id('until')} label="Até que" hint="No fim de cada volta o agente responde; com «sim» o ciclo pára.">
                    <Input
                        id={id('until')}
                        value={node.data.until ?? ''}
                        maxLength={500}
                        placeholder="Já há três cotações?"
                        onChange={(event) => onChange({ until: event.target.value })}
                    />
                </Field>
            )}

            {(node.type === 'loop' || node.type === 'repeat') && (
                <Field id={id('max')} label="No máximo" hint="Itens ou voltas. O ciclo pára sempre aqui.">
                    <Input
                        id={id('max')}
                        type="number"
                        min={1}
                        max={20}
                        value={node.data.max ?? 5}
                        onChange={(event) => onChange({ max: Math.max(1, Math.min(20, Number(event.target.value) || 1)) })}
                    />
                </Field>
            )}

            {node.type === 'wait' && (
                <Field id={id('hours')} label="Horas de espera" hint="120 h são 5 dias.">
                    <Input
                        id={id('hours')}
                        type="number"
                        min={1}
                        max={720}
                        value={node.data.hours ?? 24}
                        onChange={(event) => onChange({ hours: Math.max(1, Math.min(720, Number(event.target.value) || 1)) })}
                    />
                </Field>
            )}

            {(node.type === 'approval' || node.type === 'person') && (
                <Field id={id('person')} label={node.type === 'approval' ? 'Quem aprova' : 'Quem faz'}>
                    <NativeSelect
                        id={id('person')}
                        value={node.data.user_id ?? ''}
                        onChange={(event) => onChange({ user_id: event.target.value ? Number(event.target.value) : undefined })}
                    >
                        <option value="">A pessoa de recurso do fluxo</option>
                        {people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            )}

            {readiness && readiness.state !== 'flow' && (
                <div className="flex flex-col gap-2 rounded-lg border bg-muted/40 p-3">
                    <ReadinessBadge state={readiness.state} />
                    {readiness.reasons.map((reason) => (
                        <p key={reason} className="text-xs text-muted-foreground">
                            {reason}
                        </p>
                    ))}
                    {readiness.fixes.length > 0 && (
                        <ul className="list-disc pl-4 text-xs text-primary">
                            {readiness.fixes.map((fix) => (
                                <li key={fix}>{fixLabel[fix] ?? fix}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {node.type !== 'trigger' && !readOnly && (
                <Button type="button" variant="ghost" size="sm" className="self-start text-status-danger hover:text-status-danger" onClick={onDelete}>
                    <Trash2 />
                    Apagar bloco
                </Button>
            )}
        </fieldset>
    );
}
