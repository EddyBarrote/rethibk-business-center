import { Link } from '@inertiajs/react';
import { BookOpen, Plus } from 'lucide-react';

import { EntityRow, ListPanel } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout';
import { ago } from '@/lib/format';

interface Row {
    id: number;
    key: string;
    name: string;
    description: string;
    is_active: boolean;
    files: number;
    updated_at: string | null;
}

export default function PlatformSkillsIndex({ skills }: { skills: Row[] }) {
    const create = (
        <Button asChild>
            <Link href="/skills/create">
                <Plus />
                Nova skill global
            </Link>
        </Button>
    );

    return (
        <AdminLayout title="Skills globais">
            <PageHeader title="Skills globais" description="Pacotes de instruções que todas as organizações podem activar e dar aos seus agentes." actions={create} />
            {skills.length === 0 ? (
                <EmptyState icon={BookOpen} title="Sem skills globais" description="Escreva a primeira, por exemplo o tom de voz ou como redigir um email a um cliente." action={create} />
            ) : (
                <ListPanel>
                    {skills.map((skill) => (
                        <EntityRow
                            key={skill.id}
                            href={`/skills/${skill.id}/edit`}
                            leading={<BookOpen className="size-4 text-muted-foreground" />}
                            title={
                                <span className="flex items-center gap-1.5">
                                    {skill.name}
                                    {!skill.is_active && (
                                        <StatusBadge tone="idle" dot={false}>
                                            retirada
                                        </StatusBadge>
                                    )}
                                </span>
                            }
                            subtitle={skill.description}
                            meta={
                                <>
                                    <span className="font-mono">{skill.key}</span>
                                    <span className="tabular-nums">{skill.files} ficheiro(s)</span>
                                    <span title={skill.updated_at ?? undefined}>{ago(skill.updated_at)}</span>
                                </>
                            }
                        />
                    ))}
                </ListPanel>
            )}
        </AdminLayout>
    );
}
