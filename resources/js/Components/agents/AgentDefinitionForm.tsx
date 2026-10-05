import { Link, useForm } from '@inertiajs/react';
import { BookOpen, Bot, Brain, Cpu, Puzzle, Search } from 'lucide-react';
import { type FormEvent, type ReactNode, useMemo, useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import {
    CeilingPill,
    FormSection,
    InputErrorList,
    ScopePill,
    SectionNav,
    type SectionLink,
    SourcePill,
    str,
    useActiveSection,
} from '@/Components/agents/FormParts';
import { Field } from '@/Components/Field';
import { StatusBadge, StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import type { LevelOption, Option } from '@/types';

/*
 * The definition of an agent (docs/CAPACIDADES.md), shared by the super admin
 * console and the tenant's own admins: identity, personality and instructions,
 * autonomy and model, capabilities (tools) and skills (instructions).
 */

export interface CapabilityOption {
    id: number;
    key: string;
    name: string;
    description: string | null;
    source: string;
    scope: string;
    is_mutating: boolean;
    is_available: boolean;
    risk: number;
    ceiling: boolean;
}

export interface SkillOption {
    id: number;
    key: string;
    name: string;
    description: string;
    scope: string;
    is_available: boolean;
}

export interface AgentData {
    id: number;
    key: string;
    name: string;
    title: string | null;
    description: string | null;
    personality: string | null;
    instructions: string | null;
    department_id: number | null;
    reports_to_user_id: number | null;
    reports_to_agent_id: number | null;
    provider: string | null;
    model: string | null;
    temperature: number | null;
    max_tokens: number | null;
    max_steps: number | null;
    status: string;
    autonomy_level: number;
    capabilities: number[];
    skills: number[];
    avatar_url: string | null;
}

/** What the assistant proposes; the same fields, nothing saved yet. */
export interface AgentDraft {
    name: string;
    key: string;
    title: string;
    description: string;
    personality: string;
    instructions: string;
    autonomy_level: number;
    status: string;
    capabilities: number[];
    skills: number[];
    suggested_skills: { name: string; description: string }[];
    brief: string;
}

export interface AgentFormOptions {
    departments: { id: number; name: string }[];
    users: { id: number; name: string }[];
    agents: { id: number; name: string; title: string | null }[];
    capabilities: CapabilityOption[];
    skills: SkillOption[];
    levels: LevelOption[];
    statuses: Option[];
    providers: string[];
    defaultProvider: string;
}

export default function AgentDefinitionForm({
    agent,
    draft,
    options,
    action,
    cancelHref,
    identityAside,
    before,
    after,
    extraSections = [],
    navNote,
    skillsHref,
    capabilitiesHref,
}: {
    agent: AgentData | null;
    draft?: AgentDraft | null;
    options: AgentFormOptions;
    /** Where the form posts (create) or puts (edit). */
    action: string;
    cancelHref: string;
    /** Shown beside the identity fields, e.g. the photo. */
    identityAside?: ReactNode;
    before?: ReactNode;
    after?: ReactNode;
    extraSections?: SectionLink[];
    navNote?: string;
    skillsHref?: string;
    capabilitiesHref?: string;
}) {
    const { departments, users, agents, capabilities, skills, levels, statuses, providers, defaultProvider } = options;
    const seed = agent ?? draft;
    const form = useForm({
        key: seed?.key ?? '',
        name: seed?.name ?? '',
        title: seed?.title ?? '',
        description: seed?.description ?? '',
        personality: seed?.personality ?? '',
        instructions: seed?.instructions ?? '',
        department_id: str(agent?.department_id),
        reports_to_user_id: str(agent?.reports_to_user_id),
        reports_to_agent_id: str(agent?.reports_to_agent_id),
        status: seed?.status ?? 'draft',
        autonomy_level: str(seed?.autonomy_level ?? 1),
        provider: agent?.provider ?? '',
        model: agent?.model ?? '',
        temperature: str(agent?.temperature),
        max_tokens: str(agent?.max_tokens),
        max_steps: str(agent?.max_steps),
        capabilities: seed?.capabilities ?? [],
        skills: seed?.skills ?? [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [filter, setFilter] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            department_id: data.department_id || null,
            reports_to_user_id: data.reports_to_user_id || null,
            reports_to_agent_id: data.reports_to_agent_id || null,
            provider: data.provider || null,
            model: data.model || null,
            temperature: data.temperature || null,
            max_tokens: data.max_tokens || null,
            max_steps: data.max_steps || null,
        }));

        if (agent) {
            form.put(action, { preserveScroll: true });
        } else {
            form.post(action);
        }
    };

    const level = Number(form.data.autonomy_level);
    const visibleCapabilities = useMemo(
        () => capabilities.filter((capability) => `${capability.key} ${capability.name}`.toLowerCase().includes(filter.toLowerCase())),
        [capabilities, filter],
    );

    const toggle = (field: 'capabilities' | 'skills', id: number, on: boolean) =>
        form.setData(field, on ? [...form.data[field], id] : form.data[field].filter((value) => value !== id));

    const sections: SectionLink[] = [
        ...extraSections.filter((section) => section.id === 'assistente'),
        { id: 'identidade', label: 'Identidade', icon: Bot },
        { id: 'personalidade', label: 'Personalidade e instruções', icon: Brain },
        { id: 'modelo', label: 'Autonomia e modelo', icon: Cpu },
        { id: 'capacidades', label: 'Capacidades', icon: Puzzle, count: form.data.capabilities.length },
        { id: 'skills', label: 'Skills', icon: BookOpen, count: form.data.skills.length },
        ...extraSections.filter((section) => section.id !== 'assistente'),
    ];
    const active = useActiveSection(sections.map((section) => section.id));

    return (
        <div className="grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <SectionNav sections={sections} active={active} note={navNote} />

            <div className="flex min-w-0 flex-col gap-6">
                {before}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <FormSection id="identidade" title="Identidade" description="Quem é este colega, o que faz e a quem responde.">
                        <div className={cn('grid gap-6', identityAside && 'md:grid-cols-[minmax(0,1fr)_11rem]')}>
                            <div className="grid gap-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field id="name" label="Nome" error={errors.name}>
                                        <Input id="name" placeholder="Amélia" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                    </Field>
                                    <Field id="key" label="Chave" error={errors.key} hint="Identificador único: comercial, apoio-cliente…">
                                        <Input id="key" className="font-mono" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} required />
                                    </Field>
                                </div>
                                <Field id="title" label="Função" error={errors.title}>
                                    <Input
                                        id="title"
                                        placeholder="Gestora de Propostas Comerciais"
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                    />
                                </Field>
                                <Field id="description" label="Descrição" error={errors.description}>
                                    <Textarea id="description" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field id="department_id" label="Departamento" error={errors.department_id}>
                                        <NativeSelect id="department_id" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                            <option value="">—</option>
                                            {departments.map((department) => (
                                                <option key={department.id} value={department.id}>
                                                    {department.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field id="reports_to_user_id" label="Responde a (pessoa)" error={errors.reports_to_user_id}>
                                        <NativeSelect
                                            id="reports_to_user_id"
                                            value={form.data.reports_to_user_id}
                                            onChange={(e) => form.setData('reports_to_user_id', e.target.value)}
                                        >
                                            <option value="">—</option>
                                            {users.map((user) => (
                                                <option key={user.id} value={user.id}>
                                                    {user.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field id="reports_to_agent_id" label="Chefia no organigrama" error={errors.reports_to_agent_id}>
                                        <NativeSelect
                                            id="reports_to_agent_id"
                                            value={form.data.reports_to_agent_id}
                                            onChange={(e) => form.setData('reports_to_agent_id', e.target.value)}
                                        >
                                            <option value="">—</option>
                                            {agents.map((other) => (
                                                <option key={other.id} value={other.id}>
                                                    {other.name}
                                                    {other.title ? ` · ${other.title}` : ''}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </div>
                            </div>
                            {identityAside}
                        </div>
                    </FormSection>

                    <FormSection
                        id="personalidade"
                        title="Personalidade e instruções"
                        description="Entram no prompt de sistema, depois da identidade e antes das regras da plataforma, que não se podem sobrepor."
                    >
                        <div className="grid gap-4">
                            <Field id="personality" label="Personalidade" error={errors.personality} hint="Como fala e se comporta, em 2 a 4 frases.">
                                <Textarea id="personality" rows={3} value={form.data.personality} onChange={(e) => form.setData('personality', e.target.value)} />
                            </Field>
                            <Field id="instructions" label="Instruções" error={errors.instructions} hint="Markdown: o que faz, como faz, o que nunca faz.">
                                <Textarea
                                    id="instructions"
                                    rows={14}
                                    className="font-mono text-xs leading-relaxed"
                                    value={form.data.instructions}
                                    onChange={(e) => form.setData('instructions', e.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection
                        id="modelo"
                        title="Autonomia e modelo"
                        description="O tecto absoluto (pagamentos, facturas, contratos, pessoas, permissões) pede sempre aprovação."
                    >
                        <div className="grid gap-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="status" label="Estado" error={errors.status} hint="Em rascunho, o agente não trabalha nem aparece na equipa.">
                                    <NativeSelect id="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                        {statuses.map((status) => (
                                            <option key={status.value} value={status.value}>
                                                {status.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="autonomy_level" label="Nível de autonomia" error={errors.autonomy_level}>
                                    <NativeSelect id="autonomy_level" value={form.data.autonomy_level} onChange={(e) => form.setData('autonomy_level', e.target.value)}>
                                        {levels.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.code} · {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <div>
                                        <AutonomyBadge level={level} withLabel />
                                    </div>
                                </Field>
                            </div>
                            <div className="grid gap-4 border-t pt-5 sm:grid-cols-2">
                                <Field id="provider" label="Provedor" error={errors.provider}>
                                    <NativeSelect id="provider" value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                        <option value="">Por omissão ({defaultProvider})</option>
                                        {providers.map((provider) => (
                                            <option key={provider} value={provider}>
                                                {provider}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="model" label="Modelo" error={errors.model} hint="Vazio: o modelo por omissão do provedor.">
                                    <Input id="model" className="font-mono" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field id="temperature" label="Temperatura" error={errors.temperature}>
                                    <Input
                                        id="temperature"
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        max="2"
                                        className="font-mono"
                                        value={form.data.temperature}
                                        onChange={(e) => form.setData('temperature', e.target.value)}
                                    />
                                </Field>
                                <Field id="max_tokens" label="Máx. tokens" error={errors.max_tokens}>
                                    <Input
                                        id="max_tokens"
                                        type="number"
                                        min="1"
                                        className="font-mono"
                                        value={form.data.max_tokens}
                                        onChange={(e) => form.setData('max_tokens', e.target.value)}
                                    />
                                </Field>
                                <Field id="max_steps" label="Máx. passos" error={errors.max_steps}>
                                    <Input
                                        id="max_steps"
                                        type="number"
                                        min="1"
                                        max="50"
                                        className="font-mono"
                                        value={form.data.max_steps}
                                        onChange={(e) => form.setData('max_steps', e.target.value)}
                                    />
                                </Field>
                            </div>
                        </div>
                    </FormSection>

                    <FormSection
                        id="capacidades"
                        title="Capacidades"
                        description={
                            <>
                                O que o agente consegue <em>fazer</em>: ferramentas da plataforma, do ERP e de conectores.{' '}
                                <span className="font-mono text-foreground tabular-nums">{form.data.capabilities.length}</span> {form.data.capabilities.length === 1 ? 'seleccionada' : 'seleccionadas'}. Sem aprovação,
                                só usa uma capacidade de escrita se o seu nível for igual ou superior ao risco dela.
                            </>
                        }
                        action={
                            <div className="relative w-full sm:w-56">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input className="pl-8" placeholder="Filtrar…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                            </div>
                        }
                    >
                        <InputErrorList errors={errors} prefix="capabilities" />
                        {visibleCapabilities.length === 0 ? (
                            <p className="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                {capabilities.length === 0 ? (
                                    <>
                                        Ainda não há capacidades.{' '}
                                        {capabilitiesHref && (
                                            <Link href={capabilitiesHref} className="text-primary underline">
                                                Actualize o catálogo
                                            </Link>
                                        )}
                                    </>
                                ) : (
                                    'Nenhuma capacidade corresponde ao filtro.'
                                )}
                            </p>
                        ) : (
                            <ul className="relative grid max-h-[32rem] gap-px overflow-y-auto rounded-lg border bg-border sm:grid-cols-2">
                                {visibleCapabilities.map((capability) => {
                                    const checked = form.data.capabilities.includes(capability.id);
                                    const gated = capability.ceiling || (capability.is_mutating && level < capability.risk);

                                    return (
                                        <li key={capability.id} className="bg-card">
                                            <label
                                                className={cn(
                                                    'flex h-full cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors hover:bg-accent/60',
                                                    checked && 'bg-primary/5',
                                                    !capability.is_available && !checked && 'cursor-not-allowed opacity-60',
                                                )}
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    disabled={!capability.is_available && !checked}
                                                    onCheckedChange={(on) => toggle('capabilities', capability.id, on === true)}
                                                    className="mt-0.5"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                        <span className="text-sm font-medium">{capability.name}</span>
                                                        <SourcePill source={capability.source} scope={capability.scope} />
                                                        {capability.is_mutating ? (
                                                            <AutonomyBadge level={capability.risk} />
                                                        ) : (
                                                            <StatusBadge tone="idle" dot={false}>
                                                                leitura
                                                            </StatusBadge>
                                                        )}
                                                        {capability.ceiling && <CeilingPill />}
                                                        {!capability.is_available && (
                                                            <StatusBadge tone="idle" dot={false}>
                                                                desligada
                                                            </StatusBadge>
                                                        )}
                                                    </span>
                                                    <span className="block truncate font-mono text-[11px] text-muted-foreground">{capability.key}</span>
                                                    {checked && gated && (
                                                        <span className="mt-0.5 flex items-center gap-1.5 text-xs text-warning-strong">
                                                            <StatusDot tone="warning" pulse={false} className="size-1.5 [&>span]:size-1.5" />
                                                            Pede aprovação a este nível.
                                                        </span>
                                                    )}
                                                </span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </FormSection>

                    <FormSection
                        id="skills"
                        title="Skills"
                        description={
                            <>
                                O que o agente <em>sabe</em>: instruções da organização para tipos de trabalho. O agente vê o nome e quando se aplica, e lê o
                                resto só quando precisa.
                            </>
                        }
                    >
                        <InputErrorList errors={errors} prefix="skills" />
                        {skills.length === 0 ? (
                            <p className="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                Ainda não há skills.{' '}
                                {skillsHref && (
                                    <Link href={skillsHref} className="text-primary underline">
                                        Crie a primeira ou active uma global
                                    </Link>
                                )}
                            </p>
                        ) : (
                            <ul className="grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-2">
                                {skills.map((skill) => {
                                    const checked = form.data.skills.includes(skill.id);

                                    return (
                                        <li key={skill.id} className="bg-card">
                                            <label
                                                className={cn(
                                                    'flex h-full cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors hover:bg-accent/60',
                                                    checked && 'bg-primary/5',
                                                    !skill.is_available && !checked && 'cursor-not-allowed opacity-60',
                                                )}
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    disabled={!skill.is_available && !checked}
                                                    onCheckedChange={(on) => toggle('skills', skill.id, on === true)}
                                                    className="mt-0.5"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                        <span className="text-sm font-medium">{skill.name}</span>
                                                        <ScopePill scope={skill.scope} />
                                                        {!skill.is_available && (
                                                            <StatusBadge tone="idle" dot={false}>
                                                                desligada
                                                            </StatusBadge>
                                                        )}
                                                    </span>
                                                    <span className="line-clamp-2 text-xs text-muted-foreground">{skill.description}</span>
                                                </span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {draft && draft.suggested_skills.length > 0 && (
                            <div className="mt-4 rounded-lg border border-dashed p-3">
                                <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Skills que fariam falta</p>
                                <ul className="mt-2 grid gap-2">
                                    {draft.suggested_skills.map((suggestion) => (
                                        <li key={suggestion.name} className="text-sm">
                                            <span className="font-medium">{suggestion.name}</span>
                                            <span className="text-muted-foreground"> · {suggestion.description}</span>
                                        </li>
                                    ))}
                                </ul>
                                {skillsHref && (
                                    <Link href={`${skillsHref}/create`} className="mt-2 inline-block text-sm text-primary underline">
                                        Criar uma skill
                                    </Link>
                                )}
                            </div>
                        )}
                    </FormSection>

                    <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-between gap-3 border-t bg-background/85 px-1 py-3 backdrop-blur">
                        <Button variant="ghost" asChild>
                            <Link href={cancelHref}>Cancelar</Link>
                        </Button>
                        <div className="flex items-center gap-3">
                            {form.isDirty && <span className="hidden text-xs text-muted-foreground sm:inline">Há alterações por guardar.</span>}
                            <Button type="submit" disabled={form.processing}>
                                {agent ? 'Guardar agente' : 'Criar agente'}
                            </Button>
                        </div>
                    </div>
                </form>

                {after}
            </div>
        </div>
    );
}
