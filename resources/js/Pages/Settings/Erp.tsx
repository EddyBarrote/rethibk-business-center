import { Head, router, useForm } from '@inertiajs/react';
import { Activity, PlugZap } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';

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

const statusVariant: Record<Status, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    ok: 'default',
    untested: 'secondary',
    error: 'destructive',
    disabled: 'outline',
};

const actionLabel: Record<string, string> = {
    'erp.tool_call': 'Chamada',
    'erp.tools_list': 'Listagem de ferramentas',
    'erp.connection_test': 'Teste de ligação',
    'erp.connection_updated': 'Configuração alterada',
};

const actorLabel: Record<Call['actor_type'], string> = { user: 'Utilizador', agent: 'Agente', system: 'Sistema' };

const dateTime = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'medium' });

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

            <PageHeader title="Ligação ao ERP" description="Servidor MCP do Rethink ERP usado pelos agentes. Todas as chamadas ficam registadas na auditoria." />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Card>
                    <form onSubmit={submit}>
                        <CardHeader>
                            <CardTitle>Configuração</CardTitle>
                            <CardDescription>
                                Enquanto o servidor do ERP não estiver pronto, use o servidor falso local, com dados fictícios.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="mt-6 grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nome</Label>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-invalid={!!form.errors.name} />
                                <InputError message={form.errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transport">Transporte</Label>
                                <NativeSelect id="transport" value={form.data.transport} onChange={(e) => form.setData('transport', e.target.value as Transport)}>
                                    <option value="web">Servidor do ERP (HTTP)</option>
                                    <option value="local">Servidor falso local (desenvolvimento)</option>
                                </NativeSelect>
                                <InputError message={form.errors.transport} />
                            </div>

                            {form.data.transport === 'web' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="base_url">Endereço do servidor MCP</Label>
                                        <Input
                                            id="base_url"
                                            type="url"
                                            placeholder="https://erp.exemplo.co.mz/mcp"
                                            value={form.data.base_url}
                                            onChange={(e) => form.setData('base_url', e.target.value)}
                                            aria-invalid={!!form.errors.base_url}
                                        />
                                        <InputError message={form.errors.base_url} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="token">Token de acesso</Label>
                                        <Input
                                            id="token"
                                            type="password"
                                            autoComplete="off"
                                            placeholder={connection?.has_token ? 'Guardado. Deixe em branco para manter.' : undefined}
                                            value={form.data.token}
                                            onChange={(e) => form.setData('token', e.target.value)}
                                            aria-invalid={!!form.errors.token}
                                        />
                                        <InputError message={form.errors.token} />
                                    </div>
                                </>
                            )}

                            <div className="flex items-center gap-2">
                                <Checkbox id="enabled" checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked === true)} />
                                <Label htmlFor="enabled" className="font-normal">
                                    Ligação activa
                                </Label>
                            </div>
                        </CardContent>
                        <CardFooter className="mt-6 justify-end gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Guardar
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            Estado
                            {connection && <Badge variant={statusVariant[connection.status]}>{connection.status_label}</Badge>}
                        </CardTitle>
                        <CardDescription>
                            {connection?.last_checked_at
                                ? `Último teste: ${dateTime.format(new Date(connection.last_checked_at))}`
                                : 'Ainda não foi testada.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="mt-6 grid gap-4">
                        {!connection && <p className="text-sm text-muted-foreground">Guarde a configuração para poder testar a ligação.</p>}

                        {connection?.last_error && (
                            <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{connection.last_error}</div>
                        )}

                        {connection && connection.capabilities.length > 0 && (
                            <div className="grid gap-3">
                                <p className="text-sm text-muted-foreground">
                                    {connection.capabilities.length} ferramentas: {readTools} de leitura, {writeTools} de escrita.
                                </p>
                                <ul className="grid max-h-80 gap-1 overflow-y-auto rounded-md border p-2 text-sm">
                                    {connection.capabilities.map((tool) => (
                                        <li key={tool.name} className="flex min-w-0 items-start justify-between gap-3 rounded px-2 py-1.5 hover:bg-muted">
                                            <div className="min-w-0 flex-1">
                                                <p className="font-mono text-xs">{tool.name}</p>
                                                {tool.description && <p className="truncate text-xs text-muted-foreground">{tool.description}</p>}
                                            </div>
                                            <Badge variant={tool.read_only ? 'secondary' : 'outline'}>{tool.read_only ? 'leitura' : 'escrita'}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardContent>
                    <CardFooter className="mt-6 justify-end">
                        <Button variant="outline" onClick={test} disabled={!connection || testing || form.isDirty}>
                            <PlugZap />
                            {testing ? 'A testar…' : 'Testar ligação'}
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Chamadas recentes</CardTitle>
                    <CardDescription>As últimas 20 operações sobre o ERP, a partir da auditoria.</CardDescription>
                </CardHeader>
                <CardContent className="mt-6">
                    {calls.length === 0 ? (
                        <EmptyState icon={Activity} title="Sem chamadas" description="As chamadas ao ERP aparecem aqui assim que acontecerem." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Quando</TableHead>
                                    <TableHead>Operação</TableHead>
                                    <TableHead>Ferramenta</TableHead>
                                    <TableHead>Origem</TableHead>
                                    <TableHead>Resultado</TableHead>
                                    <TableHead className="text-right">Duração</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {calls.map((call) => (
                                    <TableRow key={call.id}>
                                        <TableCell className="whitespace-nowrap">{dateTime.format(new Date(call.created_at))}</TableCell>
                                        <TableCell>{actionLabel[call.action] ?? call.action}</TableCell>
                                        <TableCell className="font-mono text-xs">{call.tool ?? '—'}</TableCell>
                                        <TableCell>{actorLabel[call.actor_type]}</TableCell>
                                        <TableCell>
                                            <Badge variant={call.result === 'ok' ? 'secondary' : 'destructive'} title={call.error ?? undefined}>
                                                {call.result === 'ok' ? 'ok' : 'erro'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{call.duration_ms !== null ? `${call.duration_ms} ms` : '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
