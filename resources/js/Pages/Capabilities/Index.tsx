import { Head, Link, router } from '@inertiajs/react';
import { PlugZap, Puzzle, RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';

import { CeilingPill, SourcePill } from '@/Components/agents/FormParts';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { PaginationBar, usePaged } from '@/Components/Pagination';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Switch } from '@/Components/ui/switch';
import SettingsLayout from '@/Layouts/SettingsLayout';
import type { LevelOption } from '@/types';

interface CapabilityRow {
    id: number;
    key: string;
    name: string;
    description: string | null;
    source: string;
    source_label: string;
    scope: string;
    connector_id: number | null;
    platform_connector_id: number | null;
    is_mutating: boolean;
    is_available: boolean;
    is_enabled: boolean;
    risk: number;
    ceiling: boolean;
    agents: number;
}

interface Props {
    capabilities: CapabilityRow[];
    levels: LevelOption[];
}

const patch = (capability: CapabilityRow, data: { is_enabled?: boolean; risk?: number }) =>
    router.patch(`/settings/capabilities/${capability.id}`, data, { preserveScroll: true });

function CapabilityList({ rows, levels, editableRisk }: { rows: CapabilityRow[]; levels: LevelOption[]; editableRisk: boolean }) {
    const paged = usePaged(rows, 20);

    if (rows.length === 0) {
        return (
            <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">Nenhuma capacidade com este filtro.</p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <ListPanel>
                {paged.items.map((capability) => (
                    <EntityRow
                        key={capability.id}
                        className={!capability.is_available ? 'opacity-60' : undefined}
                        title={
                            <span className="flex flex-wrap items-center gap-1.5">
                                {capability.name}
                                <SourcePill source={capability.source} scope={capability.scope} />
                                {capability.ceiling && <CeilingPill />}
                                {!capability.is_available && (
                                    <StatusBadge tone="idle" dot={false}>
                                        indisponível
                                    </StatusBadge>
                                )}
                            </span>
                        }
                        subtitle={
                            <>
                                <span className="font-mono">{capability.key}</span>
                                {capability.description && <span> · {capability.description}</span>}
                            </>
                        }
                        meta={
                            <span className="tabular-nums">
                                {capability.agents} {capability.agents === 1 ? 'agente' : 'agentes'}
                            </span>
                        }
                        trailing={
                            <>
                                {capability.is_mutating ? (
                                    editableRisk ? (
                                        <NativeSelect
                                            aria-label="Risco"
                                            className="h-8 w-44 text-xs"
                                            value={capability.risk}
                                            onChange={(e) => patch(capability, { risk: Number(e.target.value) })}
                                        >
                                            {levels.map((level) => (
                                                <option key={level.value} value={level.value}>
                                                    Sem aprovação a partir de {level.code}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    ) : (
                                        <AutonomyBadge level={capability.risk} />
                                    )
                                ) : (
                                    <StatusBadge tone="idle" dot={false}>
                                        leitura
                                    </StatusBadge>
                                )}
                                <Switch
                                    aria-label={capability.is_enabled ? 'Desligar' : 'Ligar'}
                                    checked={capability.is_enabled}
                                    onCheckedChange={(on) => patch(capability, { is_enabled: on })}
                                />
                            </>
                        }
                    />
                ))}
            </ListPanel>
            {rows.length > 20 && <PaginationBar {...paged.pager} noun={['capacidade', 'capacidades']} />}
        </div>
    );
}

const origins = [
    { value: 'all', label: 'Todas' },
    { value: 'platform', label: 'Plataforma' },
    { value: 'erp', label: 'ERP' },
    { value: 'write', label: 'Só escrita' },
] as const;

export default function CapabilitiesIndex({ capabilities, levels }: Props) {
    const [filter, setFilter] = useState('');
    const [syncing, setSyncing] = useState(false);
    const match = (capability: CapabilityRow) =>
        `${capability.key} ${capability.name} ${capability.description ?? ''}`.toLowerCase().includes(filter.toLowerCase());

    const [origin, setOrigin] = useState<(typeof origins)[number]['value']>('all');
    const ofOrigin = (c: CapabilityRow) =>
        origin === 'all' || (origin === 'erp' ? c.source === 'mcp' : origin === 'platform' ? c.source === 'local' : c.is_mutating);
    const platform = capabilities.filter((c) => c.source !== 'connector' && match(c) && ofOrigin(c));
    const fromGlobal = capabilities.filter((c) => c.source === 'connector' && c.scope === 'global' && match(c));
    const own = capabilities.filter((c) => c.scope === 'tenant' && match(c));

    return (
        <SettingsLayout>
            <Head title="Capacidades" />
            <PageHeader
                title="Capacidades"
                description="O que os agentes conseguem fazer: ferramentas da plataforma, do ERP e de conectores. Atribuem-se a cada agente na página de edição dele."
                actions={
                    <>
                        <Button
                            variant="outline"
                            disabled={syncing}
                            onClick={() =>
                                router.post(
                                    '/settings/capabilities/sync',
                                    {},
                                    { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) },
                                )
                            }
                        >
                            <RefreshCw className={syncing ? 'animate-spin' : undefined} />
                            Actualizar catálogo
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/settings/integrations">
                                <PlugZap />
                                Conectores
                            </Link>
                        </Button>
                    </>
                }
            />

            {capabilities.length === 0 ? (
                <EmptyState
                    icon={Puzzle}
                    title="Catálogo vazio"
                    description="Carregue em «Actualizar catálogo» para trazer as capacidades da plataforma e as ferramentas do ERP."
                />
            ) : (
                <div className="flex flex-col gap-8">
                    <div className="relative w-full sm:w-72">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="pl-8"
                            placeholder="Filtrar capacidades…"
                            aria-label="Filtrar capacidades"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        />
                    </div>
                    <Section title="Da empresa" action={<span className="text-xs text-muted-foreground">risco definido por si</span>}>
                        {own.length === 0 && !filter ? (
                            <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                Ainda sem conectores próprios. Ligue uma API ou um servidor MCP da empresa em{' '}
                                <Link href="/settings/integrations" className="font-medium text-foreground underline-offset-4 hover:underline">
                                    Integrações
                                </Link>
                                .
                            </p>
                        ) : (
                            <CapabilityList rows={own} levels={levels} editableRisk />
                        )}
                    </Section>
                    {fromGlobal.length > 0 && (
                        <Section title="De conectores globais">
                            <CapabilityList rows={fromGlobal} levels={levels} editableRisk={false} />
                        </Section>
                    )}
                    <Section title="Plataforma e ERP" action={<span className="text-xs text-muted-foreground">risco definido pela Rethink</span>}>
                        <div className="flex flex-wrap items-center gap-1">
                            {origins.map((option) => (
                                <Button
                                    key={option.value}
                                    size="xs"
                                    variant={origin === option.value ? 'secondary' : 'ghost'}
                                    className={origin === option.value ? 'font-medium' : 'font-normal text-muted-foreground'}
                                    onClick={() => setOrigin(option.value)}
                                >
                                    {option.label}
                                </Button>
                            ))}
                        </div>
                        <CapabilityList rows={platform} levels={levels} editableRisk={false} />
                    </Section>
                </div>
            )}
        </SettingsLayout>
    );
}
