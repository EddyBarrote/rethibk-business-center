import { Head, router, useForm } from '@inertiajs/react';
import { Activity, PlugZap } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import { Property } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Transport = 'web' | 'local';
type Status = 'untested' | 'ok' | 'error' | 'disabled';

interface Capability {
    name: string;
    title: string | null;
    description: string | null;
    read_only: boolean;
}

interface Connection {
    name: string;
    transport: Transport;
    base_url: string | null;
    has_token: boolean;
    enabled: boolean;
    status: Status;
    status_label: string;
    last_checked_at: string | null;
    last_error: string | null;
    capabilities: Capability[];
}

interface Call {
    id: number;
    action: string;
    tool: string | null;
    tool_name: string | null;
    actor_type: 'user' | 'agent' | 'system';
    result: 'ok' | 'denied' | 'error';
    duration_ms: number | null;
    error: string | null;
    created_at: string;
}

interface Props {
    connection: Connection | null;
    defaults: { transport: Transport };
    calls: Call[];
}

const statusTone: Record<Status, Tone> = { ok: 'success', untested: 'idle', error: 'danger', disabled: 'idle' };

const resultTone: Record<Call['result'], Tone> = { ok: 'success', denied: 'warning', error: 'danger' };
const resultLabel: Record<Call['result'], string> = { ok: 'ok', denied: 'recusada', error: 'erro' };

const actionLabel: Record<string, string> = {
    'erp.tool_call': 'Chamada',
    'erp.tools_list': 'Listagem de ferramentas',
    'erp.connection_test': 'Teste de ligação',
    'erp.connection_updated': 'Configuração alterada',
};

const actorLabel: Record<Call['actor_type'], string> = { user: 'Utilizador', agent: 'Agente', system: 'Sistema' };

const head = 'h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase';

/** Settings row: what the group is about on the left, its controls on the right. */
function SettingsBlock({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-4 lg:grid-cols-[16rem_1fr] lg:gap-8">
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}

export default function ErpSettings({ connection, defaults, calls }: Props) {
    const [testing, setTesting] = useState(false);
    const form = useForm({
        name: connection?.name ?? 'Rethink ERP',
        transport: connection?.transport ?? defaults.transport,
        base_url: connection?.base_url ?? '',
        token: '',
        enabled: connection?.enabled ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings/erp', { preserveScroll: true, onSuccess: () => form.setData('token', '') });
    };

    const test = () => {
        router.post('/settings/erp/test', {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) });
    };

    const readTools = connection?.capabilities.filter((tool) => tool.read_only).length ?? 0;
    const writeTools = (connection?.capabilities.length ?? 0) - readTools;

    return (
        <AppLayout>
            <Head title="Ligação ao ERP" />

            <PageHeader
                title="Ligação ao ERP"
                description="Servidor MCP do Rethink ERP usado pelos agentes. Todas as chamadas ficam registadas na auditoria."
                actions={
                    <Button variant="outline" onClick={test} disabled={!connection || testing || form.isDirty}>
                        <PlugZap />
                        {testing ? 'A testar…' : 'Testar ligação'}
                    </Button>
                }
            />

            <SettingsBlock
                title="Estado"
                description={
                    connection
                        ? 'Resultado do último teste e ferramentas que o ERP expõe aos agentes.'
                        : 'Guarde a configuração para poder testar a ligação.'
                }
            >
                <div className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                    <div>
                        <Property label="Ligação">
                            {connection ? (
                                <StatusBadge tone={statusTone[connection.status]}>{connection.status_label}</StatusBadge>
                            ) : (
                                'Por configurar'
                            )}
                        </Property>
                        <Property label="Último teste">
                            {connection?.last_checked_at ? (
                                <span title={dateTime(connection.last_checked_at)}>{ago(connection.last_checked_at)}</span>
                            ) : (
                                <span className="text-muted-foreground">Ainda não foi testada</span>
                            )}
                        </Property>
                        {connection && (
                            <Property label="Ferramentas">
                                <span className="tabular-nums">
                                    {connection.capabilities.length} · {readTools} de leitura, {writeTools} de escrita
                                </span>
                            </Property>
                        )}
                    </div>

                    {connection?.last_error && (
                        <div className="rounded-lg border border-status-danger/30 bg-status-danger/10 px-4 py-3 font-mono text-xs break-words text-status-danger">
                            {connection.last_error}
                        </div>
                    )}

                    {connection && connection.capabilities.length > 0 && (
                        <ul className="max-h-80 divide-y overflow-y-auto rounded-lg border text-sm">
                            {connection.capabilities.map((tool) => (
                                <li key={tool.name} className="flex min-w-0 items-start justify-between gap-3 px-3 py-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="flex min-w-0 items-baseline gap-2">
                                            <span className="truncate text-sm">{tool.title ?? tool.name}</span>
                                            {tool.title && (
                                                <span className="hidden shrink-0 font-mono text-[11px] text-muted-foreground sm:inline">
                                                    {tool.name}
                                                </span>
                                            )}
                                        </p>
                                        {tool.description && <p className="truncate text-xs text-muted-foreground">{tool.description}</p>}
                                    </div>
                                    <StatusBadge tone={tool.read_only ? 'idle' : 'warning'} dot={false}>
                                        {tool.read_only ? 'leitura' : 'escrita'}
                                    </StatusBadge>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </SettingsBlock>

            <SettingsBlock
                title="Configuração"
                description="Enquanto o servidor do ERP não estiver pronto, use o servidor de demonstração, com dados fictícios."
            >
                <form onSubmit={submit} className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="name" label="Nome" error={form.errors.name}>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                aria-invalid={!!form.errors.name}
                            />
                        </Field>
                        <Field id="transport" label="Transporte" error={form.errors.transport}>
                            <NativeSelect
                                id="transport"
                                value={form.data.transport}
                                onChange={(e) => form.setData('transport', e.target.value as Transport)}
                            >
                                <option value="web">Servidor do ERP (HTTP)</option>
                                <option value="local">Servidor de demonstração (dados fictícios)</option>
                            </NativeSelect>
                        </Field>
                    </div>

                    {form.data.transport === 'web' && (
                        <>
                            <Field id="base_url" label="Endereço do servidor MCP" error={form.errors.base_url}>
                                <Input
                                    id="base_url"
                                    type="url"
                                    className="font-mono"
                                    placeholder="https://erp.exemplo.co.mz/mcp"
                                    value={form.data.base_url}
                                    onChange={(e) => form.setData('base_url', e.target.value)}
                                    aria-invalid={!!form.errors.base_url}
                                />
                            </Field>
                            <Field id="token" label="Token de acesso" error={form.errors.token}>
                                <Input
                                    id="token"
                                    type="password"
                                    autoComplete="off"
                                    className="font-mono"
                                    placeholder={connection?.has_token ? 'Guardado. Deixe em branco para manter.' : undefined}
                                    value={form.data.token}
                                    onChange={(e) => form.setData('token', e.target.value)}
                                    aria-invalid={!!form.errors.token}
                                />
                            </Field>
                        </>
                    )}

                    <div className="grid gap-1">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="enabled"
                                className="mt-0.5"
                                checked={form.data.enabled}
                                onCheckedChange={(checked) => form.setData('enabled', checked === true)}
                            />
                            <div className="grid gap-0.5">
                                <Label htmlFor="enabled">Ligação activa</Label>
                                <p className="text-xs text-muted-foreground">Desligada, os agentes deixam de poder ler ou escrever no ERP.</p>
                            </div>
                        </div>
                        <InputError message={form.errors.enabled} />
                    </div>

                    <div className="flex items-center justify-between gap-2 border-t pt-4">
                        <span className="text-xs text-muted-foreground">{form.isDirty ? 'Guarde antes de testar a ligação.' : ''}</span>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </div>
                </form>
            </SettingsBlock>

            <SettingsBlock title="Chamadas recentes" description="As últimas 20 operações sobre o ERP, a partir da auditoria.">
                {calls.length === 0 ? (
                    <EmptyState
                        icon={Activity}
                        title="Sem chamadas"
                        description="Teste a ligação para registar a primeira chamada; as chamadas dos agentes aparecem aqui assim que acontecerem."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table className="table-stack">
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className={head}>Quando</TableHead>
                                    <TableHead className={head}>Operação</TableHead>
                                    <TableHead className={head}>Ferramenta</TableHead>
                                    <TableHead className={head}>Origem</TableHead>
                                    <TableHead className={head}>Resultado</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Duração</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {calls.map((call) => (
                                    <TableRow key={call.id}>
                                        <TableCell
                                            className="px-4 py-2 text-xs whitespace-nowrap text-muted-foreground"
                                            title={dateTime(call.created_at)}
                                        >
                                            {ago(call.created_at)}
                                        </TableCell>
                                        <TableCell data-label="Operação" className="px-4 py-2">
                                            {actionLabel[call.action] ?? call.action}
                                        </TableCell>
                                        <TableCell data-label="Ferramenta" className="px-4 py-2" title={call.tool ?? undefined}>
                                            {call.tool_name ?? call.tool ?? '—'}
                                        </TableCell>
                                        <TableCell data-label="Origem" className="px-4 py-2 text-muted-foreground">
                                            {actorLabel[call.actor_type]}
                                        </TableCell>
                                        <TableCell data-label="Resultado" className="px-4 py-2">
                                            <StatusBadge tone={resultTone[call.result]} title={call.error ?? undefined}>
                                                {resultLabel[call.result]}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell data-label="Duração" className="px-4 py-2 text-right font-mono text-xs tabular-nums">
                                            {call.duration_ms !== null ? `${call.duration_ms} ms` : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </SettingsBlock>
        </AppLayout>
    );
}
