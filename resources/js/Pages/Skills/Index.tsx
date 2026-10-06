import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, Globe, Plus } from 'lucide-react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Switch } from '@/Components/ui/switch';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago } from '@/lib/format';

interface SkillRow {
    id: number;
    key: string;
    name: string;
    description: string;
    is_enabled: boolean;
    agents: number;
    files: number;
    updated_at: string | null;
}

interface GlobalSkillRow {
    id: number;
    key: string;
    name: string;
    description: string;
    files: number;
    activated: boolean;
    agents: number;
}

export default function SkillsIndex({ skills, globalSkills }: { skills: SkillRow[]; globalSkills: GlobalSkillRow[] }) {
    return (
        <SettingsLayout>
            <Head title="Skills" />
            <PageHeader
                title="Skills"
                description="O que os agentes sabem: instruções da organização para tipos de trabalho (como fazer uma proposta, a política de compras, o tom da marca). O agente lê uma skill quando ela se aplica."
                actions={
                    <Button asChild>
                        <Link href="/settings/skills/create">
                            <Plus />
                            Nova skill
                        </Link>
                    </Button>
                }
            />

            <div className="flex flex-col gap-8">
                <Section title={`Da empresa · ${skills.length}`}>
                    {skills.length === 0 ? (
                        <EmptyState
                            icon={BookOpen}
                            title="Sem skills próprias"
                            description="Escreva a primeira: o nome, quando se aplica e as instruções, com modelos ou exemplos em anexo."
                            action={
                                <Button asChild>
                                    <Link href="/settings/skills/create">
                                        <Plus />
                                        Nova skill
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <ListPanel>
                            {skills.map((skill) => (
                                <EntityRow
                                    key={skill.id}
                                    href={`/settings/skills/${skill.id}/edit`}
                                    leading={<BookOpen className="size-4 text-muted-foreground" />}
                                    title={
                                        <span className="flex items-center gap-1.5">
                                            {skill.name}
                                            {!skill.is_enabled && (
                                                <StatusBadge tone="idle" dot={false}>
                                                    desligada
                                                </StatusBadge>
                                            )}
                                        </span>
                                    }
                                    subtitle={skill.description}
                                    meta={
                                        <>
                                            <span className="tabular-nums">{skill.agents} agente(s)</span>
                                            <span className="tabular-nums">{skill.files} ficheiro(s)</span>
                                            <span title={skill.updated_at ?? undefined}>{ago(skill.updated_at)}</span>
                                        </>
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>

                <Section
                    title={`Globais · ${globalSkills.length}`}
                    action={<span className="text-xs text-muted-foreground">escritas pela Rethink; active as que quiser</span>}
                >
                    {globalSkills.length === 0 ? (
                        <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                            Ainda não há skills globais.
                        </p>
                    ) : (
                        <ListPanel>
                            {globalSkills.map((skill) => (
                                <EntityRow
                                    key={skill.id}
                                    leading={<Globe className="size-4 text-muted-foreground" />}
                                    title={skill.name}
                                    subtitle={skill.description}
                                    meta={
                                        <>
                                            {skill.activated && <span className="tabular-nums">{skill.agents} agente(s)</span>}
                                            <span className="tabular-nums">{skill.files} ficheiro(s)</span>
                                        </>
                                    }
                                    trailing={
                                        <Switch
                                            aria-label={skill.activated ? 'Desligar' : 'Activar'}
                                            checked={skill.activated}
                                            onCheckedChange={(on) =>
                                                router.put(`/settings/skills/global/${skill.id}`, { activated: on }, { preserveScroll: true })
                                            }
                                        />
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>
            </div>
        </SettingsLayout>
    );
}
