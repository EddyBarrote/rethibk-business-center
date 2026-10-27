import { Link, useForm } from '@inertiajs/react';
import { BookOpen, Bot, Brain, ChevronRight, Cpu, Puzzle, ShieldCheck, Search } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { CeilingPill, FormSection, InputErrorList, ScopePill, SectionNav, type SectionLink, SourcePill, str } from '@/Components/agents/FormParts';
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
    /** Written on the agent for the entry screen; empty shows `suggested`. */
    suggestions?: string[];
    /** What its capabilities suggest until someone writes them. */
    suggested?: string[];
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
    /** The models an agent can be given (config agents.models), with their price per million tokens. */
    models?: { provider: string; model: string; label: string; provider_label?: string; hint: string; input: number | null; output: number | null }[];
    /** Every provider of the list, connected (with a key in .env) or not. */
    model_providers?: { key: string; label: string; connected: boolean; env: string }[];
    /** Ticked on a new agent: searching and reading the internet. */
    defaultCapabilities?: number[];
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
    const {
        departments,
        users,
        agents,
        capabilities,
        skills,
        levels,
        statuses,
        defaultProvider,
        models = [],
        model_providers: modelProviders = [],
    } = options;
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
        capabilities: seed?.capabilities ?? options.defaultCapabilities ?? [],
        skills: seed?.skills ?? [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [filter, setFilter] = useState('');
    const [onlyChosen, setOnlyChosen] = useState(false);
    // Step 1 of the Modelo tab; until chosen, the agent's own provider.
    const [pickedProvider, setPickedProvider] = useState<string | null>(null);

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
        () =>
            capabilities.filter(
                (capability) =>
                    `${capability.key} ${capability.name} ${capability.description ?? ''}`.toLowerCase().includes(filter.toLowerCase()) &&
                    (!onlyChosen || form.data.capabilities.includes(capability.id)),
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [capabilities, filter, onlyChosen],
    );

    const toggle = (field: 'capabilities' | 'skills', id: number, on: boolean) =>
        form.setData(field, on ? [...form.data[field], id] : form.data[field].filter((value) => value !== id));

    const sections: SectionLink[] = [
        ...extraSections.filter((section) => section.id === 'assistente'),
        { id: 'identidade', label: 'Identidade', icon: Bot },
        { id: 'personalidade', label: 'Personalidade e instruções', icon: Brain },
        { id: 'autonomia', label: 'Autonomia', icon: ShieldCheck },
        { id: 'modelo', label: 'Modelo', icon: Cpu },
        { id: 'capacidades', label: 'Capacidades', icon: Puzzle, count: form.data.capabilities.length },
        { id: 'skills', label: 'Skills', icon: BookOpen, count: form.data.skills.length },
        ...extraSections.filter((section) => section.id !== 'assistente'),
    ];
    // One section at a time, as tabs (docs/DECISOES.md, "Agentes: configuração em separadores"); the address keeps it (#modelo).
    const ids = sections.map((section) => section.id).filter((id) => id !== 'assistente');
    const [tab, setTab] = useState(() => {
        const hash = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
        return ids.includes(hash) ? hash : ids[0];
    });
    useEffect(() => {
        const onHash = () => {
            const hash = window.location.hash.slice(1);
            if (ids.includes(hash)) {
                setTab(hash);
                window.scrollTo({ top: 0 });
            }
        };
        window.addEventListener('hashchange', onHash);
        // The browser jumps to #modelo; with tabs the page starts at the top.
        if (ids.includes(window.location.hash.slice(1))) {
            requestAnimationFrame(() => window.scrollTo({ top: 0 }));
        }

        return () => window.removeEventListener('hashchange', onHash);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ids.join(',')]);

    // A tab with an error after saving is marked, and the first one opens.
    const fieldsOf: Record<string, string[]> = {
        identidade: ['name', 'key', 'title', 'description', 'department_id', 'reports_to_user_id', 'reports_to_agent_id'],
        personalidade: ['personality', 'instructions'],
        autonomia: ['status', 'autonomy_level'],
        modelo: ['provider', 'model', 'temperature', 'max_tokens', 'max_steps'],
        capacidades: ['capabilities'],
        skills: ['skills'],
    };
    const withError = (id: string) => Object.keys(errors).some((key) => (fieldsOf[id] ?? []).includes(key.split('.')[0]));
    const errorKeys = Object.keys(errors).join(',');
    useEffect(() => {
        const first = ids.find((id) => withError(id));
        if (first && !withError(tab)) {
            setTab(first);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errorKeys]);
    const tabs = sections.map((section) => ({ ...section, error: withError(section.id) }));

    return (
        <div className="grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <SectionNav sections={tabs} active={tab} note={navNote} onSelect={(id) => setTab(id)} />

            <div className="flex min-w-0 flex-col gap-8">
                {tab === ids[0] && before}

                <form onSubmit={submit} className="flex flex-col gap-8">
                    <div hidden={tab !== 'identidade'}>
                        <FormSection id="identidade" title="Identidade" description="Quem é este colega, o que faz e a quem responde.">
                            <div className={cn('grid gap-6', identityAside && 'md:grid-cols-[minmax(0,1fr)_11rem]')}>
                                <div className="grid gap-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field id="name" label="Nome" error={errors.name}>
                                            <Input
                                                id="name"
                                                placeholder="Amélia"
                                                value={form.data.name}
                                                onChange={(e) => form.setData('name', e.target.value)}
                                                required
                                            />
                                        </Field>
                                        <Field id="key" label="Chave" error={errors.key} hint="Identificador único: comercial, apoio-cliente…">
                                            <Input
                                                id="key"
                                                className="font-mono"
                                                value={form.data.key}
                                                onChange={(e) => form.setData('key', e.target.value)}
                                                required
                                            />
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
                                        <Textarea
                                            id="description"
                                            rows={2}
                                            value={form.data.description}
                                            onChange={(e) => form.setData('description', e.target.value)}
                                        />
                                    </Field>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field id="department_id" label="Departamento" error={errors.department_id}>
                                            <NativeSelect
                                                id="department_id"
                                                value={form.data.department_id}
                                                onChange={(e) => form.setData('department_id', e.target.value)}
                                            >
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
                    </div>

                    <div hidden={tab !== 'personalidade'}>
                        <FormSection
                            id="personalidade"
                            title="Personalidade e instruções"
                            description="Entram no prompt de sistema, depois da identidade e antes das regras da plataforma, que não se podem sobrepor."
                        >
                            <div className="grid gap-4">
                                <Field
                                    id="personality"
                                    label="Personalidade"
                                    error={errors.personality}
                                    hint="Como fala e se comporta, em 2 a 4 frases."
                                >
                                    <Textarea
                                        id="personality"
                                        rows={3}
                                        value={form.data.personality}
                                        onChange={(e) => form.setData('personality', e.target.value)}
                                    />
                                </Field>
                                <Field
                                    id="instructions"
                                    label="Instruções"
                                    error={errors.instructions}
                                    hint="Markdown: o que faz, como faz, o que nunca faz."
                                >
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
                    </div>

                    <div hidden={tab !== 'autonomia'}>
                        <FormSection
                            id="autonomia"
                            title="Autonomia"
                            description="O tecto absoluto (pagamentos, facturas, contratos, pessoas, permissões) pede sempre aprovação."
                        >
                            <div className="grid gap-6">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        id="status"
                                        label="Estado"
                                        error={errors.status}
                                        hint="Em rascunho, o agente não trabalha nem aparece na equipa."
                                    >
                                        <NativeSelect id="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                            {statuses.map((status) => (
                                                <option key={status.value} value={status.value}>
                                                    {status.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </div>
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="mb-2 text-sm font-medium">Nível de autonomia</legend>
                                    {levels.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors hover:bg-accent/50',
                                                level === option.value && 'bg-accent',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="autonomy_level"
                                                value={option.value}
                                                checked={level === option.value}
                                                onChange={() => form.setData('autonomy_level', String(option.value))}
                                                className="accent-primary"
                                            />
                                            <AutonomyBadge level={option.value} />
                                            <span className={cn(level === option.value && 'font-medium')}>{option.label}</span>
                                        </label>
                                    ))}
                                    {errors.autonomy_level && <p className="text-sm text-destructive">{errors.autonomy_level}</p>}
                                </fieldset>
                            </div>
                        </FormSection>
                    </div>

                    <div hidden={tab !== 'modelo'}>
                        <FormSection
                            id="modelo"
                            title="Modelo"
                            description="O modelo de IA com que o agente pensa e escreve. Os mais caros raciocinam melhor; os mais baratos chegam para triagem e tarefas simples."
                        >
                            <div className="grid gap-6">
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="sr-only">Modelo</legend>
                                    {(() => {
                                        type ModelOption = (typeof models)[number];
                                        const row = (option: ModelOption) => {
                                            const chosen = form.data.model === option.model && (option.model !== '' || form.data.provider === '');

                                            return (
                                                <label
                                                    key={`${option.provider}:${option.model}` || 'default'}
                                                    className={cn(
                                                        'flex cursor-pointer items-start gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors hover:bg-accent/50',
                                                        chosen && 'bg-accent',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="model"
                                                        checked={chosen}
                                                        onChange={() =>
                                                            form.setData((data) => ({ ...data, provider: option.provider, model: option.model }))
                                                        }
                                                        className="mt-1 accent-primary"
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className={cn('block', chosen && 'font-medium')}>{option.label}</span>
                                                        <span className="block text-xs text-muted-foreground">{option.hint}</span>
                                                    </span>
                                                    {option.model !== '' && (
                                                        <span
                                                            className="shrink-0 text-right text-[11px] text-muted-foreground tabular-nums"
                                                            title="US$ por milhão de tokens: entrada / saída"
                                                        >
                                                            {option.input !== null && option.output !== null
                                                                ? `${option.input.toLocaleString('pt-PT')} / ${option.output.toLocaleString('pt-PT')} US$`
                                                                : 'preço por definir'}
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        };
                                        const legacy =
                                            form.data.model && !models.some((m) => m.model === form.data.model)
                                                ? [
                                                      {
                                                          provider: form.data.provider,
                                                          model: form.data.model,
                                                          label: form.data.model,
                                                          hint: 'Fora da lista.',
                                                          input: null,
                                                          output: null,
                                                      },
                                                  ]
                                                : [];
                                        const providerKey =
                                            pickedProvider ?? (form.data.provider || models.find((m) => m.model === form.data.model)?.provider || '');
                                        const providerOf = (key: string) => modelProviders.find((p) => p.key === key);

                                        return (
                                            <>
                                                <div className="grid gap-1.5 sm:max-w-sm">
                                                    <label htmlFor="ai-provider" className="text-sm font-medium">
                                                        1. Provedor
                                                    </label>
                                                    <NativeSelect
                                                        id="ai-provider"
                                                        value={providerKey}
                                                        onChange={(event) => {
                                                            const next = event.target.value;
                                                            setPickedProvider(next);
                                                            // The platform's default needs no second step; another provider starts on its first model.
                                                            const first = models.find((m) => m.provider === next);
                                                            form.setData((data) => ({
                                                                ...data,
                                                                provider: next,
                                                                model: next === '' ? '' : (first?.model ?? ''),
                                                            }));
                                                        }}
                                                    >
                                                        <option value="">Por omissão da plataforma ({models[0]?.label ?? defaultProvider})</option>
                                                        {modelProviders.map((provider) => (
                                                            <option key={provider.key} value={provider.key} disabled={!provider.connected}>
                                                                {provider.connected ? provider.label : `${provider.label} (sem chave)`}
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                    {modelProviders.some((p) => !p.connected) && (
                                                        <p className="text-xs text-muted-foreground">
                                                            Os provedores sem chave ligam-se em{' '}
                                                            <a href="/settings/ai-providers" className="text-primary hover:underline">
                                                                Definições › Provedores de IA
                                                            </a>
                                                            .
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="mt-5 flex flex-col gap-1">
                                                    <p className="mb-1 text-sm font-medium">2. Modelo</p>
                                                    {providerKey === '' ? (
                                                        <p className="px-2.5 text-sm text-muted-foreground">
                                                            Usa o modelo por omissão da plataforma, hoje {models[0]?.label ?? defaultProvider}. Muda
                                                            quando a plataforma mudar.
                                                        </p>
                                                    ) : (
                                                        <>
                                                            {models.filter((m) => m.provider === providerKey).map(row)}
                                                            {legacy.filter((m) => m.provider === providerKey).map(row)}
                                                            {!providerOf(providerKey)?.connected && (
                                                                <p className="px-2.5 text-sm text-muted-foreground">
                                                                    Este provedor ainda não tem chave.
                                                                </p>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </>
                                        );
                                    })()}
                                    {(errors.model || errors.provider) && (
                                        <p className="text-sm text-destructive">{errors.model ?? errors.provider}</p>
                                    )}
                                </fieldset>
                            </div>
                        </FormSection>
                    </div>

                    <div hidden={tab !== 'capacidades'}>
                        <FormSection
                            id="capacidades"
                            title="Capacidades"
                            description={
                                <>
                                    O que o agente consegue <em>fazer</em>: ferramentas da plataforma, do ERP e de conectores.{' '}
                                    <span className="font-medium text-foreground tabular-nums">{form.data.capabilities.length}</span>{' '}
                                    {form.data.capabilities.length === 1 ? 'escolhida' : 'escolhidas'}. Uma capacidade que escreve só é usada sem
                                    aprovação se o nível do agente chegar ao risco dela.
                                </>
                            }
                            action={
                                <div className="flex w-full flex-col gap-2">
                                    <div className="relative w-full">
                                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            className="h-8 pl-8"
                                            placeholder="Procurar capacidade…"
                                            value={filter}
                                            onChange={(e) => setFilter(e.target.value)}
                                        />
                                    </div>
                                    <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                                        <Checkbox checked={onlyChosen} onCheckedChange={(on) => setOnlyChosen(on === true)} />
                                        Só as escolhidas
                                    </label>
                                </div>
                            }
                        >
                            <InputErrorList errors={errors} prefix="capabilities" />
                            {capabilities.length === 0 ? (
                                <p className="py-6 text-sm text-muted-foreground">
                                    Ainda não há capacidades.{' '}
                                    {capabilitiesHref && (
                                        <Link href={capabilitiesHref} className="text-primary underline">
                                            Actualize o catálogo
                                        </Link>
                                    )}
                                </p>
                            ) : (
                                <CapabilityGroups
                                    capabilities={visibleCapabilities}
                                    selected={form.data.capabilities}
                                    level={level}
                                    filtering={filter !== '' || onlyChosen}
                                    onToggle={(id, on) => toggle('capabilities', id, on)}
                                    onToggleMany={(ids, on) =>
                                        form.setData(
                                            'capabilities',
                                            on
                                                ? [...new Set([...form.data.capabilities, ...ids])]
                                                : form.data.capabilities.filter((id) => !ids.includes(id)),
                                        )
                                    }
                                />
                            )}
                        </FormSection>
                    </div>

                    <div hidden={tab !== 'skills'}>
                        <FormSection
                            id="skills"
                            title="Skills"
                            description={
                                <>
                                    O que o agente <em>sabe</em>: instruções da organização para tipos de trabalho. O agente vê o nome e quando se
                                    aplica, e lê o resto só quando precisa.
                                </>
                            }
                        >
                            <InputErrorList errors={errors} prefix="skills" />
                            {skills.length === 0 ? (
                                <p className="py-6 text-sm text-muted-foreground">
                                    Ainda não há skills.{' '}
                                    {skillsHref && (
                                        <Link href={skillsHref} className="text-primary underline">
                                            Crie a primeira ou active uma global
                                        </Link>
                                    )}
                                </p>
                            ) : (
                                <ul className="grid gap-x-4 gap-y-0.5 sm:grid-cols-2">
                                    {skills.map((skill) => {
                                        const checked = form.data.skills.includes(skill.id);

                                        return (
                                            <li key={skill.id}>
                                                <label
                                                    className={cn(
                                                        'flex h-full cursor-pointer items-start gap-3 rounded-lg px-2.5 py-2 transition-colors hover:bg-accent/50',
                                                        checked && 'bg-primary/[0.04]',
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
                                <div className="mt-4 rounded-lg bg-muted/40 p-3">
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
                    </div>

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

/** Where a capability belongs, from its source and the start of its key. */
const areas: { label: string; prefixes: string[] }[] = [
    {
        label: 'Trabalho e equipa',
        prefixes: [
            'agents',
            'tasks',
            'approvals',
            'questions',
            'escalate',
            'workflow',
            'workflows',
            'conversations',
            'notify',
            'followups',
            'briefings',
            'platform',
            'skills',
        ],
    },
    { label: 'Email e comunicação', prefixes: ['email', 'comms'] },
    {
        label: 'Conhecimento, ficheiros e pesquisa',
        prefixes: ['knowledge', 'memory', 'files', 'documents', 'reports', 'company', 'web', 'code', 'analysis'],
    },
    { label: 'Finanças', prefixes: ['finance', 'bank', 'budget'] },
    { label: 'Clientes e vendas', prefixes: ['clients', 'sales', 'contracts', 'tenders'] },
    { label: 'Compras e fornecedores', prefixes: ['procurement', 'suppliers'] },
    { label: 'Pessoas', prefixes: ['hr'] },
];

function areaOf(capability: CapabilityOption): string {
    if (capability.source === 'mcp') {
        return 'ERP';
    }
    if (capability.source === 'connector') {
        return 'Conectores';
    }
    const prefix = capability.key.split('.')[0];

    return areas.find((area) => area.prefixes.includes(prefix))?.label ?? 'Outras';
}

const areaOrder = [...areas.map((area) => area.label), 'ERP', 'Conectores', 'Outras'];

/**
 * The capabilities grouped by area, each group with how many are chosen and
 * a way to choose all of it or none. A group with nothing chosen starts
 * folded, unless the person is searching.
 */
function CapabilityGroups({
    capabilities,
    selected,
    level,
    filtering,
    onToggle,
    onToggleMany,
}: {
    capabilities: CapabilityOption[];
    selected: number[];
    level: number;
    filtering: boolean;
    onToggle: (id: number, on: boolean) => void;
    onToggleMany: (ids: number[], on: boolean) => void;
}) {
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const groups = areaOrder
        .map((label) => ({ label, items: capabilities.filter((capability) => areaOf(capability) === label) }))
        .filter((group) => group.items.length > 0);

    if (groups.length === 0) {
        return <p className="py-6 text-sm text-muted-foreground">Nenhuma capacidade corresponde à procura.</p>;
    }

    return (
        <div className="flex flex-col gap-1">
            {groups.map((group) => {
                const chosen = group.items.filter((capability) => selected.includes(capability.id)).length;
                const available = group.items.filter((capability) => capability.is_available).map((capability) => capability.id);
                const expanded = open[group.label] ?? (filtering || chosen > 0);

                return (
                    <div key={group.label} className="flex flex-col">
                        <div className="flex items-center gap-2 py-1.5">
                            <button
                                type="button"
                                onClick={() => setOpen((current) => ({ ...current, [group.label]: !expanded }))}
                                aria-expanded={expanded}
                                className="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium"
                            >
                                <ChevronRight className={cn('size-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-90')} />
                                <span className="truncate">{group.label}</span>
                                <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                    {chosen}/{group.items.length}
                                </span>
                            </button>
                            {expanded && available.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => onToggleMany(available, chosen < available.length)}
                                    className="text-xs text-muted-foreground hover:text-foreground"
                                >
                                    {chosen < available.length ? 'Escolher todas' : 'Tirar todas'}
                                </button>
                            )}
                        </div>
                        {expanded && (
                            <ul className="mb-3 grid gap-x-4 gap-y-0.5 pl-6 sm:grid-cols-2">
                                {group.items.map((capability) => {
                                    const checked = selected.includes(capability.id);
                                    const gated = capability.ceiling || (capability.is_mutating && level < capability.risk);

                                    return (
                                        <li key={capability.id}>
                                            <label
                                                title={capability.description ?? capability.key}
                                                className={cn(
                                                    'flex h-full cursor-pointer items-start gap-2.5 rounded-lg px-2.5 py-1.5 transition-colors hover:bg-accent/50',
                                                    checked && 'bg-primary/[0.04]',
                                                    !capability.is_available && !checked && 'cursor-not-allowed opacity-50',
                                                )}
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    disabled={!capability.is_available && !checked}
                                                    onCheckedChange={(on) => onToggle(capability.id, on === true)}
                                                    className="mt-0.5"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                        <span className="text-sm">{capability.name}</span>
                                                        {capability.is_mutating ? (
                                                            <AutonomyBadge level={capability.risk} />
                                                        ) : (
                                                            <span className="text-[11px] text-muted-foreground">leitura</span>
                                                        )}
                                                        {capability.ceiling && <CeilingPill />}
                                                        {capability.source === 'connector' && (
                                                            <SourcePill source={capability.source} scope={capability.scope} />
                                                        )}
                                                        {!capability.is_available && (
                                                            <span className="text-[11px] text-muted-foreground">desligada</span>
                                                        )}
                                                    </span>
                                                    {capability.description && (
                                                        <span className="line-clamp-1 text-xs text-muted-foreground">{capability.description}</span>
                                                    )}
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
                    </div>
                );
            })}
        </div>
    );
}
