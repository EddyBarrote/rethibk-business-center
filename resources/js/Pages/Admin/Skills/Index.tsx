import { router } from '@inertiajs/react';
import { Lock, Puzzle, RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AdminLayout from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';
import type { LevelOption } from '@/types';

interface SkillRow {
    id: number;
    key: string;
    name: string;
    description: string | null;
    source: 'local' | 'mcp';
    is_mutating: boolean;
    is_available: boolean;
    risk: number;
    ceiling: string | null;
    agents: number;
}

export default function SkillsIndex({ tenant, skills, levels }: { tenant: { id: number; name: string }; skills: SkillRow[]; levels: LevelOption[] }) {
    const [filter, setFilter] = useState('');
    const [syncing, setSyncing] = useState(false);
    const visible = skills.filter((skill) => `${skill.key} ${skill.name}`.toLowerCase().includes(filter.toLowerCase()));

    const sync = () =>
        router.post(
            `/tenants/${tenant.id}/skills/sync`,
            {},
            { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) },
        );

    return (
        <AdminLayout
            title={`Skills · ${tenant.name}`}
            breadcrumbs={[{ label: 'Organizações', href: '/tenants' }, { label: tenant.name, href: `/tenants/${tenant.id}` }, { label: 'Skills' }]}
        >
            <PageHeader
                title="Skills"
                description="O risco de uma skill de escrita é o nível de autonomia de que um agente precisa para a usar sem aprovação."
                actions={
                    <Button variant="outline" disabled={syncing} onClick={sync}>
                        <RefreshCw className={syncing ? 'animate-spin' : undefined} />
                        Actualizar do ERP
                    </Button>
                }
            />

            {skills.length === 0 ? (
                <EmptyState
                    icon={Puzzle}
                    title="Sem skills"
                    description="Actualize do ERP para importar as skills do servidor MCP desta organização."
                    action={
                        <Button size="sm" variant="outline" disabled={syncing} onClick={sync}>
                            <RefreshCw className={syncing ? 'animate-spin' : undefined} />
                            Actualizar do ERP
                        </Button>
                    }
                />
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <div className="relative w-full max-w-64">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input className="pl-8" placeholder="Filtrar…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                        </div>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {visible.length} de {skills.length}
                        </span>
                    </div>

                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Skill
                                    </TableHead>
                                    <TableHead className="h-9 text-xs font-medium tracking-wide text-muted-foreground uppercase">Origem</TableHead>
                                    <TableHead className="h-9 text-xs font-medium tracking-wide text-muted-foreground uppercase">Tipo</TableHead>
                                    <TableHead className="h-9 text-xs font-medium tracking-wide text-muted-foreground uppercase">Risco</TableHead>
                                    <TableHead className="h-9 px-4 text-right text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Agentes
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.length === 0 && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell colSpan={5} className="px-4 py-8 text-center text-sm text-muted-foreground">
                                            Nenhuma skill corresponde ao filtro.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {visible.map((skill) => (
                                    <TableRow key={skill.id} className={cn(!skill.is_available && 'opacity-50')}>
                                        <TableCell className="max-w-md px-4 py-2.5 whitespace-normal">
                                            <p className="flex items-center gap-2 font-medium">
                                                {skill.name}
                                                {!skill.is_available && (
                                                    <StatusBadge tone="idle" dot={false}>
                                                        indisponível
                                                    </StatusBadge>
                                                )}
                                            </p>
                                            <p className="font-mono text-[11px] text-muted-foreground">{skill.key}</p>
                                            {skill.ceiling && (
                                                <p className="mt-1 flex items-center gap-1 text-xs text-status-danger">
                                                    <Lock className="size-3" />
                                                    Tecto absoluto: {skill.ceiling}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="py-2.5">
                                            <span className="inline-flex h-5 items-center rounded-full border px-2 font-mono text-[11px] text-muted-foreground">
                                                {skill.source === 'mcp' ? 'ERP' : 'local'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="py-2.5">
                                            <StatusBadge tone={skill.is_mutating ? 'warning' : 'idle'} dot={false}>
                                                {skill.is_mutating ? 'escrita' : 'leitura'}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="py-2.5">
                                            {skill.is_mutating ? (
                                                <NativeSelect
                                                    className="w-56"
                                                    value={skill.risk}
                                                    aria-label={`Risco de ${skill.name}`}
                                                    onChange={(e) =>
                                                        router.put(
                                                            `/tenants/${tenant.id}/skills/${skill.id}`,
                                                            { risk: Number(e.target.value) },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {levels.map((level) => (
                                                        <option key={level.value} value={level.value}>
                                                            {level.code} · {level.label}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">sem gate</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="px-4 py-2.5 text-right tabular-nums">{skill.agents}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
