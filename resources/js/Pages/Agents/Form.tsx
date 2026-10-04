import { Head, router, useForm } from '@inertiajs/react';
import { ImageUp, Sparkles, Trash2, WandSparkles } from 'lucide-react';
import { type FormEvent, useRef } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import AgentDefinitionForm, { type AgentData, type AgentDraft, type AgentFormOptions } from '@/Components/agents/AgentDefinitionForm';
import { FormSection } from '@/Components/agents/FormParts';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';

interface Props extends AgentFormOptions {
    agent: AgentData | null;
    draft: AgentDraft | null;
    can_draft?: boolean;
    can_generate_avatar: boolean;
}

const examples = [
    'Alguém que prepare propostas comerciais a partir dos pedidos que chegam por email, com os nossos preços e o nosso tom.',
    'Uma pessoa de apoio ao cliente que responda a perguntas sobre encomendas e escale reclamações ao gestor de conta.',
    'Um assistente de RH que faça a triagem de CVs para as vagas abertas e marque entrevistas.',
];

/** Describe the colleague you need; the AI drafts the definition below. */
function Assistant({ brief, available }: { brief?: string; available: boolean }) {
    const form = useForm({ brief: brief ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/agents/new/draft', { preserveScroll: false });
    };

    return (
        <section id="assistente" className="scroll-mt-20 overflow-hidden rounded-xl border border-primary/30 bg-primary/[0.03]">
            <form onSubmit={submit} className="grid gap-3 p-5">
                <div className="flex items-start gap-3">
                    <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary">
                        <Sparkles className="size-4" />
                    </span>
                    <div className="space-y-1">
                        <h2 className="text-sm font-semibold">Descreva o colega de que precisa</h2>
                        <p className="text-sm text-muted-foreground">
                            A IA propõe nome, função, personalidade, instruções, capacidades e skills. Nada fica guardado até rever e carregar em «Criar agente».
                        </p>
                    </div>
                </div>
                <Textarea
                    rows={4}
                    placeholder={examples[0]}
                    value={form.data.brief}
                    onChange={(e) => form.setData('brief', e.target.value)}
                    aria-label="Descrição do colega"
                />
                {form.errors.brief && <p className="text-sm text-destructive">{form.errors.brief}</p>}
                {!available && (
                    <p className="rounded-lg border border-status-warning/30 bg-status-warning/10 px-3 py-2 text-sm">
                        O assistente precisa da chave do provedor de IA (por exemplo <span className="font-mono">GEMINI_API_KEY</span> no .env). Até lá, pode
                        preencher a definição à mão abaixo.
                    </p>
                )}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-1.5">
                        {examples.map((example, index) => (
                            <button
                                key={example}
                                type="button"
                                onClick={() => form.setData('brief', example)}
                                className="rounded-full border bg-card px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground"
                            >
                                {['Propostas', 'Apoio ao cliente', 'Recrutamento'][index]}
                            </button>
                        ))}
                    </div>
                    <Button type="submit" disabled={!available || form.processing || form.data.brief.trim().length < 15}>
                        <WandSparkles />
                        {form.processing ? 'A preparar a proposta…' : brief ? 'Propor de novo' : 'Propor colega'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

/** The photo beside the identity fields: upload, generate, or back to initials. */
function AvatarPanel({ agent, canGenerate }: { agent: AgentData; canGenerate: boolean }) {
    const input = useRef<HTMLInputElement>(null);
    const upload = useForm<{ avatar: File | null }>({ avatar: null });
    const generating = useForm({});

    const send = (file: File | undefined) => {
        if (!file) {
            return;
        }

        upload.transform(() => ({ avatar: file }));
        upload.post(`/agents/${agent.id}/avatar`, { preserveScroll: true, forceFormData: true, onFinish: () => input.current && (input.current.value = '') });
    };

    return (
        <div className="flex flex-col items-center gap-3 md:border-l md:pl-6">
            <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-28 rounded-2xl text-3xl" />
            <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => send(e.target.files?.[0])} />
            <div className="flex w-full flex-col gap-1.5">
                <Button type="button" size="sm" variant="outline" disabled={upload.processing} onClick={() => input.current?.click()}>
                    <ImageUp />
                    Carregar foto
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={!canGenerate || generating.processing}
                    title={canGenerate ? 'Gera um retrato a partir da função e da personalidade' : 'Falta a chave do provedor de imagens no .env'}
                    onClick={() => generating.post(`/agents/${agent.id}/avatar/generate`, { preserveScroll: true })}
                >
                    <Sparkles />
                    {generating.processing ? 'A gerar…' : 'Gerar com IA'}
                </Button>
                {agent.avatar_url && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground"
                        onClick={() => router.delete(`/agents/${agent.id}/avatar`, { preserveScroll: true })}
                    >
                        <Trash2 />
                        Usar iniciais
                    </Button>
                )}
            </div>
            {upload.errors.avatar && <p className="text-center text-xs text-destructive">{upload.errors.avatar}</p>}
        </div>
    );
}

export default function AgentFormPage({ agent, draft, can_draft = false, can_generate_avatar, ...options }: Props) {
    const title = agent ? `Editar ${agent.name}` : 'Novo agente';
    const statusLabel = options.statuses.find((status) => status.value === agent?.status)?.label ?? agent?.status;

    return (
        <AppLayout
            wide
            breadcrumbs={[{ label: 'Agentes', href: '/agents' }, ...(agent ? [{ label: agent.name, href: `/agents/${agent.id}` }] : []), { label: agent ? 'Editar' : 'Novo agente' }]}
        >
            <Head title={title} />
            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        <AgentAvatar name={agent?.name ?? draft?.name ?? 'Novo agente'} url={agent?.avatar_url} className="size-8 text-xs" />
                        {agent ? agent.name : 'Novo agente'}
                        {agent && <StatusBadge tone={agentTone(agent.status)}>{statusLabel}</StatusBadge>}
                    </span>
                }
                description={
                    agent
                        ? 'Quem é, como fala, o que faz, que capacidades e skills usa e até onde pode agir sozinho.'
                        : 'Um agente é um colega de trabalho: tem nome, cara, função, capacidades (o que consegue fazer) e skills (o que sabe).'
                }
                actions={agent && <AutonomyBadge level={agent.autonomy_level} withLabel />}
            />

            <AgentDefinitionForm
                key={draft ? `draft-${draft.key}-${draft.brief.length}` : (agent?.id ?? 'new')}
                agent={agent}
                draft={draft}
                options={options}
                action={agent ? `/agents/${agent.id}` : '/agents'}
                cancelHref={agent ? `/agents/${agent.id}` : '/agents'}
                skillsHref="/skills"
                capabilitiesHref="/capabilities"
                extraSections={agent ? [] : [{ id: 'assistente', label: 'Assistente', icon: Sparkles }]}
                navNote={agent ? undefined : 'A foto fica disponível depois de criar o agente.'}
                before={!agent && <Assistant brief={draft?.brief} available={can_draft} />}
                identityAside={agent && <AvatarPanel agent={agent} canGenerate={can_generate_avatar} />}
                after={
                    draft &&
                    !agent && (
                        <FormSection id="proposta" title="Sobre esta proposta" description="Foi escrita pela IA a partir da sua descrição. Reveja tudo antes de criar.">
                            <p className="text-sm text-muted-foreground">«{draft.brief}»</p>
                        </FormSection>
                    )
                }
            />
        </AppLayout>
    );
}
