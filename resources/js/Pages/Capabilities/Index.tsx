import { Head, router } from '@inertiajs/react';
import { Globe, Pencil, Plug, Plus, Puzzle, RefreshCw, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { CeilingPill, SourcePill } from '@/Components/agents/FormParts';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { type ConnectorData, ConnectorSheet } from '@/Components/catalog/ConnectorSheet';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { ago } from '@/lib/format';
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

interface GlobalConnector {
    id: number;
    key: string;
    name: string;
    description: string | null;
    kind: string;
    kind_label: string;
    tools: number;
    is_mutating: boolean;
    activated: boolean;
}

interface Props {
    capabilities: CapabilityRow[];
    connectors: ConnectorData[];
    globalConnectors: GlobalConnector[];
    levels: LevelOption[];
}

const patch = (capability: CapabilityRow, data: { is_enabled?: boolean; risk?: number }) => router.patch(`/capabilities/${capability.id}`, data, { preserveScroll: true });

function CapabilityList({ rows, levels, editableRisk }: { rows: CapabilityRow[]; levels: LevelOption[]; editableRisk: boolean }) {
    if (rows.length === 0) {
        return <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">Nada aqui.</p>;
    }

    return (
        <ListPanel>
            {rows.map((capability) => (
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
                    meta={<span className="tabular-nums">{capability.agents} agente(s)</span>}
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
    );
}

export default function CapabilitiesIndex({ capabilities, connectors, globalConnectors, levels }: Props) {
    const [filter, setFilter] = useState('');
    const [syncing, setSyncing] = useState(false);
    const [editing, setEditing] = useState<ConnectorData | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const match = (capability: CapabilityRow) => `${capability.key} ${capability.name} ${capability.description ?? ''}`.toLowerCase().includes(filter.toLowerCase());

    const platform = capabilities.filter((c) => c.source !== 'connector' && match(c));
    const fromGlobal = capabilities.filter((c) => c.source === 'connector' && c.scope === 'global' && match(c));
    const own = capabilities.filter((c) => c.scope === 'tenant' && match(c));

    const openSheet = (connector: ConnectorData | null) => {
        setEditing(connector);
        setSheetOpen(true);
    };

    return (
        <AppLayout>
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
                                router.post('/capabilities/sync', {}, { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) })
                            }
                        >
                            <RefreshCw className={syncing ? 'animate-spin' : undefined} />
                            Actualizar catálogo
                        </Button>
                        <Button onClick={() => openSheet(null)}>
                            <Plus />
                            Novo conector
                        </Button>
                    </>
                }
            />

            <Tabs defaultValue="catalogo" className="gap-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <TabsList>
                        <TabsTrigger value="catalogo">Catálogo · {capabilities.length}</TabsTrigger>
                        <TabsTrigger value="conectores">Conectores · {connectors.length}</TabsTrigger>
                        <TabsTrigger value="globais">Globais · {globalConnectors.length}</TabsTrigger>
                    </TabsList>
                    <div className="relative w-full sm:w-64">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input className="pl-8" placeholder="Filtrar capacidades…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                    </div>
                </div>

                <TabsContent value="catalogo" className="flex flex-col gap-8">
                    {capabilities.length === 0 ? (
                        <EmptyState
                            icon={Puzzle}
                            title="Catálogo vazio"
                            description="Carregue em «Actualizar catálogo» para trazer as capacidades da plataforma e as ferramentas do ERP."
                        />
                    ) : (
                        <>
                            <Section title="Da empresa" action={<span className="text-xs text-muted-foreground">risco definido por si</span>}>
                                {own.length === 0 && !filter ? (
                                    <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                        Ainda sem conectores próprios. Ligue uma API ou um servidor MCP da empresa com «Novo conector».
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
                                <CapabilityList rows={platform} levels={levels} editableRisk={false} />
                            </Section>
                        </>
                    )}
                </TabsContent>

                <TabsContent value="conectores">
                    {connectors.length === 0 ? (
                        <EmptyState
                            icon={Plug}
                            title="Sem conectores"
                            description="Um conector liga os agentes a um sistema da empresa: um servidor MCP (todas as ferramentas dele) ou uma acção HTTP numa API."
                            action={
                                <Button onClick={() => openSheet(null)}>
                                    <Plus />
                                    Novo conector
                                </Button>
                            }
                        />
                    ) : (
                        <ListPanel>
                            {connectors.map((connector) => (
                                <EntityRow
                                    key={connector.id}
                                    leading={<Plug className="size-4 text-muted-foreground" />}
                                    title={
                                        <span className="flex items-center gap-1.5">
                                            {connector.name}
                                            <StatusBadge tone="idle" dot={false}>
                                                {connector.kind_label}
                                            </StatusBadge>
                                            {!connector.is_active && (
                                                <StatusBadge tone="idle" dot={false}>
                                                    desligado
                                                </StatusBadge>
                                            )}
                                            {connector.last_error && <StatusBadge tone="danger">com erro</StatusBadge>}
                                        </span>
                                    }
                                    subtitle={<span className="font-mono">{connector.url}</span>}
                                    meta={
                                        <>
                                            <span className="tabular-nums">{connector.tools} ferramenta(s)</span>
                                            <span title={connector.last_synced_at ?? undefined}>lido {ago(connector.last_synced_at)}</span>
                                        </>
                                    }
                                    trailing={
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Ler ferramentas"
                                                onClick={() => router.post(`/connectors/${connector.id}/refresh`, {}, { preserveScroll: true })}
                                            >
                                                <RefreshCw />
                                            </Button>
                                            <Button variant="ghost" size="icon" aria-label="Editar" onClick={() => openSheet(connector)}>
                                                <Pencil />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Apagar"
                                                className="hover:text-status-danger"
                                                onClick={() =>
                                                    confirm(`Apagar ${connector.name}? As capacidades dele saem de todos os agentes.`) &&
                                                    router.delete(`/connectors/${connector.id}`, { preserveScroll: true })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </>
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </TabsContent>

                <TabsContent value="globais">
                    {globalConnectors.length === 0 ? (
                        <EmptyState icon={Globe} title="Sem conectores globais" description="A Rethink ainda não disponibilizou conectores para todas as organizações." />
                    ) : (
                        <ListPanel>
                            {globalConnectors.map((connector) => (
                                <EntityRow
                                    key={connector.id}
                                    leading={<Globe className="size-4 text-muted-foreground" />}
                                    title={
                                        <span className="flex items-center gap-1.5">
                                            {connector.name}
                                            <StatusBadge tone="idle" dot={false}>
                                                {connector.kind_label}
                                            </StatusBadge>
                                        </span>
                                    }
                                    subtitle={connector.description}
                                    meta={<span className="tabular-nums">{connector.tools} ferramenta(s)</span>}
                                    trailing={
                                        <Switch
                                            aria-label={connector.activated ? 'Desligar' : 'Activar'}
                                            checked={connector.activated}
                                            onCheckedChange={(on) =>
                                                on
                                                    ? router.post(`/capabilities/global/${connector.id}`, {}, { preserveScroll: true })
                                                    : router.delete(`/capabilities/global/${connector.id}`, { preserveScroll: true })
                                            }
                                        />
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </TabsContent>
            </Tabs>

            <ConnectorSheet base="/connectors" connector={editing} open={sheetOpen} onOpenChange={setSheetOpen} scope="tenant" />
        </AppLayout>
    );
}
