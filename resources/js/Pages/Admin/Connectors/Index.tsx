import { router } from '@inertiajs/react';
import { Globe, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { EntityRow, ListPanel } from '@/Components/Blocks';
import { type ConnectorData, ConnectorSheet } from '@/Components/catalog/ConnectorSheet';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout';
import { ago } from '@/lib/format';

type Row = ConnectorData & { tenants: number; tool_list: { name: string; title: string | null; read_only: boolean }[] };

export default function PlatformConnectorsIndex({ connectors }: { connectors: Row[] }) {
    const [editing, setEditing] = useState<ConnectorData | null>(null);
    const [open, setOpen] = useState(false);
    const edit = (connector: ConnectorData | null) => {
        setEditing(connector);
        setOpen(true);
    };
    const create = (
        <Button onClick={() => edit(null)}>
            <Plus />
            Novo conector global
        </Button>
    );

    return (
        <AdminLayout title="Conectores globais">
            <PageHeader
                title="Conectores globais"
                description="Servidores MCP e acções HTTP oferecidos a todas as organizações. Cada uma activa os que quer na sua página de capacidades."
                actions={create}
            />
            {connectors.length === 0 ? (
                <EmptyState icon={Globe} title="Sem conectores globais" description="Ligue um serviço útil a todas as organizações, por exemplo o câmbio do dia." action={create} />
            ) : (
                <ListPanel>
                    {connectors.map((connector) => (
                        <EntityRow
                            key={connector.id}
                            leading={<Globe className="size-4 text-muted-foreground" />}
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
                            subtitle={connector.last_error ?? connector.tool_list.map((tool) => tool.name).join(', ') ?? connector.url}
                            meta={
                                <>
                                    <span className="tabular-nums">{connector.tools} ferramenta(s)</span>
                                    <span className="tabular-nums">{connector.tenants} organização(ões)</span>
                                    <span title={connector.last_synced_at ?? undefined}>testado {ago(connector.last_synced_at)}</span>
                                </>
                            }
                            trailing={
                                <>
                                    <Button variant="ghost" size="icon" aria-label="Testar" onClick={() => router.post(`/connectors/${connector.id}/test`, {}, { preserveScroll: true })}>
                                        <RefreshCw />
                                    </Button>
                                    <Button variant="ghost" size="icon" aria-label="Editar" onClick={() => edit(connector)}>
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Apagar"
                                        className="hover:text-status-danger"
                                        onClick={() =>
                                            confirm(`Apagar ${connector.name}? Sai de todas as organizações.`) && router.delete(`/connectors/${connector.id}`, { preserveScroll: true })
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
            <ConnectorSheet base="/connectors" connector={editing} open={open} onOpenChange={setOpen} scope="global" />
        </AdminLayout>
    );
}
