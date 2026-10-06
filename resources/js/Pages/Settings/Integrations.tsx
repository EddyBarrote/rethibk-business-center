import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Database, Globe, Pencil, Plug, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { type ConnectorData, ConnectorSheet } from '@/Components/catalog/ConnectorSheet';
import { ConfirmDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Switch } from '@/Components/ui/switch';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, plural } from '@/lib/format';

interface ErpSummary {
    name: string;
    base_url: string | null;
    status: 'ok' | 'untested' | 'error' | 'disabled';
    status_label: string;
    last_checked_at: string | null;
    tools: number;
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
    can_erp: boolean;
    can_connectors: boolean;
    erp: ErpSummary | null;
    connectors: ConnectorData[];
    globalConnectors: GlobalConnector[];
}

const erpTone: Record<ErpSummary['status'], Tone> = { ok: 'success', untested: 'idle', error: 'danger', disabled: 'idle' };

/** Integrações: the ERP and the connectors, the ways the agents reach other systems. */
export default function Integrations({ can_erp, can_connectors, erp, connectors, globalConnectors }: Props) {
    const [editing, setEditing] = useState<ConnectorData | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);

    const openSheet = (connector: ConnectorData | null) => {
        setEditing(connector);
        setSheetOpen(true);
    };

    return (
        <SettingsLayout>
            <Head title="Integrações" />
            <PageHeader
                title="Integrações"
                description="Os sistemas a que os agentes chegam: o ERP da empresa e os conectores para outras APIs e servidores MCP. Cada chamada fica na auditoria."
                actions={
                    can_connectors && (
                        <Button onClick={() => openSheet(null)}>
                            <Plus />
                            Novo conector
                        </Button>
                    )
                }
            />

            {can_erp && (
                <Section title="ERP">
                    <ListPanel>
                        <EntityRow
                            href="/settings/integrations/erp"
                            leading={
                                <span className="flex size-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <Database className="size-4" />
                                </span>
                            }
                            title={
                                <span className="flex flex-wrap items-center gap-1.5">
                                    {erp?.name ?? 'Rethink ERP'}
                                    {erp ? (
                                        <StatusBadge tone={erpTone[erp.status]}>{erp.status_label}</StatusBadge>
                                    ) : (
                                        <StatusBadge tone="idle" dot={false}>
                                            por configurar
                                        </StatusBadge>
                                    )}
                                </span>
                            }
                            subtitle={
                                erp
                                    ? 'Servidor MCP do Rethink ERP usado pelos agentes.'
                                    : 'Ligue o servidor MCP do Rethink ERP para os agentes lerem e registarem dados no ERP.'
                            }
                            meta={
                                erp && (
                                    <>
                                        <span className="tabular-nums">{plural(erp.tools, 'ferramenta', 'ferramentas')}</span>
                                        <span>{erp.last_checked_at ? `testado ${ago(erp.last_checked_at)}` : 'por testar'}</span>
                                    </>
                                )
                            }
                            trailing={<ChevronRight className="size-4 text-muted-foreground" />}
                        />
                    </ListPanel>
                </Section>
            )}

            {can_connectors && (
                <Section title="Conectores da empresa" action={<span className="text-xs text-muted-foreground">MCP ou HTTP</span>}>
                    {connectors.length === 0 ? (
                        <EmptyState
                            icon={Plug}
                            title="Sem conectores"
                            description="Um conector liga os agentes a um sistema da empresa: um servidor MCP (todas as ferramentas dele) ou uma acção HTTP numa API."
                            example="A API do banco para ler extractos, ou o servidor MCP do sistema de RH."
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
                                        <span className="flex flex-wrap items-center gap-1.5">
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
                                    subtitle={<span className="font-mono break-all">{connector.url}</span>}
                                    meta={
                                        <>
                                            <span className="tabular-nums">{plural(connector.tools, 'ferramenta', 'ferramentas')}</span>
                                            <span title={connector.last_synced_at ?? undefined}>lido {ago(connector.last_synced_at)}</span>
                                        </>
                                    }
                                    trailing={
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ler as ferramentas de ${connector.name}`}
                                                title="Ler as ferramentas"
                                                onClick={() =>
                                                    router.post(
                                                        `/settings/integrations/connectors/${connector.id}/refresh`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <RefreshCw />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Editar ${connector.name}`}
                                                title="Editar"
                                                onClick={() => openSheet(connector)}
                                            >
                                                <Pencil />
                                            </Button>
                                            <ConfirmDialog
                                                title={`Apagar o conector ${connector.name}?`}
                                                description="As capacidades dele saem de todos os agentes que as usam. Não se pode desfazer."
                                                confirmLabel="Apagar conector"
                                                destructive
                                                onConfirm={() =>
                                                    router.delete(`/settings/integrations/connectors/${connector.id}`, { preserveScroll: true })
                                                }
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Apagar ${connector.name}`}
                                                        title="Apagar"
                                                        className="hover:text-status-danger"
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                }
                                            />
                                        </>
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                    {connectors.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            As ferramentas de cada conector aparecem em{' '}
                            <Link href="/settings/capabilities" className="font-medium text-foreground underline-offset-4 hover:underline">
                                Capacidades
                            </Link>
                            , onde se liga, desliga e define o risco de cada uma.
                        </p>
                    )}
                </Section>
            )}

            {can_connectors && (
                <Section title="Conectores globais" action={<span className="text-xs text-muted-foreground">disponibilizados pela Rethink</span>}>
                    {globalConnectors.length === 0 ? (
                        <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                            A Rethink ainda não disponibilizou conectores para todas as organizações.
                        </p>
                    ) : (
                        <ListPanel>
                            {globalConnectors.map((connector) => (
                                <EntityRow
                                    key={connector.id}
                                    leading={<Globe className="size-4 text-muted-foreground" />}
                                    title={
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            {connector.name}
                                            <StatusBadge tone="idle" dot={false}>
                                                {connector.kind_label}
                                            </StatusBadge>
                                        </span>
                                    }
                                    subtitle={connector.description}
                                    meta={<span className="tabular-nums">{plural(connector.tools, 'ferramenta', 'ferramentas')}</span>}
                                    trailing={
                                        <Switch
                                            aria-label={connector.activated ? `Desligar ${connector.name}` : `Activar ${connector.name}`}
                                            checked={connector.activated}
                                            onCheckedChange={(on) =>
                                                on
                                                    ? router.post(`/settings/integrations/global/${connector.id}`, {}, { preserveScroll: true })
                                                    : router.delete(`/settings/integrations/global/${connector.id}`, { preserveScroll: true })
                                            }
                                        />
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>
            )}

            {can_connectors && (
                <ConnectorSheet
                    base="/settings/integrations/connectors"
                    connector={editing}
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    scope="tenant"
                />
            )}
        </SettingsLayout>
    );
}
