import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Lock, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AdminLayout from '@/Layouts/AdminLayout';
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

    return (
        <AdminLayout title={`Skills · ${tenant.name}`}>
            <div>
                <Link href={`/tenants/${tenant.id}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    {tenant.name}
                </Link>
            </div>
            <PageHeader
                title="Skills"
                description="O risco de uma skill de escrita é o nível de autonomia de que um agente precisa para a usar sem aprovação."
                actions={
                    <Button
                        variant="outline"
                        disabled={syncing}
                        onClick={() =>
                            router.post(`/tenants/${tenant.id}/skills/sync`, {}, { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) })
                        }
                    >
                        <RefreshCw className={syncing ? 'animate-spin' : undefined} />
                        Actualizar do ERP
                    </Button>
                }
            />

            <Card>
                <CardContent className="grid gap-4">
                    <Input className="max-w-64" placeholder="Filtrar…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Skill</TableHead>
                                <TableHead>Origem</TableHead>
                                <TableHead>Tipo</TableHead>
                                <TableHead>Risco</TableHead>
                                <TableHead className="text-right">Agentes</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visible.map((skill) => (
                                <TableRow key={skill.id} className={skill.is_available ? undefined : 'opacity-50'}>
                                    <TableCell className="max-w-md">
                                        <p className="font-medium">{skill.name}</p>
                                        <p className="font-mono text-[11px] text-muted-foreground">{skill.key}</p>
                                        {skill.ceiling && (
                                            <p className="mt-1 flex items-center gap-1 text-xs text-destructive">
                                                <Lock className="size-3" />
                                                Tecto absoluto: {skill.ceiling}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">{skill.source === 'mcp' ? 'ERP' : 'local'}</Badge>
                                    </TableCell>
                                    <TableCell>{skill.is_mutating ? 'escrita' : 'leitura'}</TableCell>
                                    <TableCell>
                                        {skill.is_mutating ? (
                                            <NativeSelect
                                                className="w-56"
                                                value={skill.risk}
                                                onChange={(e) => router.put(`/tenants/${tenant.id}/skills/${skill.id}`, { risk: Number(e.target.value) }, { preserveScroll: true })}
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
                                    <TableCell className="text-right tabular-nums">{skill.agents}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
