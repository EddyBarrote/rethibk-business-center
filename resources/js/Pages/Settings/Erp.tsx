import { Head, Link, router, useForm } from '@inertiajs/react';
import { Activity, PlugZap, Search } from 'lucide-react';
import { type FormEvent, type ReactNode, useMemo, useState } from 'react';

import { Property } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { PaginationBar, usePaged } from '@/Components/Pagination';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime } from '@/lib/format';

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

interface Props {
    connection: Connection | null;
    defaults: { transport: Transport };
    activity: { today: number; errors_today: number };
}

const statusTone: Record<Status, Tone> = { ok: 'success', untested: 'idle', error: 'danger', disabled: 'idle' };

type Tab = 'status' | 'tools' | 'config';
type ToolFilter = 'all' | 'read' | 'write';

const tabs: Tab[] = ['status', 'tools', 'config'];

/** The open tab lives in ?tab=, so a reload or a shared link keeps it. */
const initialTab = (): Tab => {
    const tab = new URLSearchParams(window.location.search).get('tab');

    return tabs.includes(tab as Tab) ? (tab as Tab) : 'status';
};

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

export default function ErpSettings({ connection, defaults, activity }: Props) {
    const [testing, setTesting] = useState(false);
    const [tab, setTab] = useState<Tab>(initialTab);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<ToolFilter>('all');
    const form = useForm({
        name: connection?.name ?? 'Rethink ERP',
        transport: connection?.transport ?? defaults.transport,
        base_url: connection?.base_url ?? '',
        token: '',
        enabled: connection?.enabled ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings/integrations/erp', { preserveScroll: true, onSuccess: () => form.setData('token', '') });
    };

    const test = () => {
        router.post(
            '/settings/integrations/erp/test',
            {},
            { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) },
        );
    };

    const changeTab = (value: string) => {
        setTab(value as Tab);
        const url = new URL(window.location.href);
        if (value === 'status') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', value);
        }
        window.history.replaceState(window.history.state, '', url);
    };

    const tools = useMemo(() => connection?.capabilities ?? [], [connection]);
    const readTools = tools.filter((tool) => tool.read_only).length;
    const writeTools = tools.length - readTools;

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return tools.filter(
            (tool) =>
                (filter === 'all' || (filter === 'read') === tool.read_only) &&
                (needle === '' || [tool.name, tool.title, tool.description].some((text) => text?.toLowerCase().includes(needle))),
        );
    }, [tools, query, filter]);
    const paged = usePaged(shown, 25);

    return (
        <SettingsLayout crumbs={[{ label: 'Ligação ao ERP' }]}>
            <Head title="Ligação ao ERP" />

            <PageHeader
                title="Ligação ao ERP"
                description="Servidor MCP do Rethink ERP usado pelos agentes. Todas as chamadas ficam registadas na auditoria."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href="/settings/integrations/erp/activity">
                                <Activity />
                                Actividade
                            </Link>
                        </Button>
                        <Button variant="outline" onClick={test} disabled={!connection || testing || form.isDirty}>
                            <PlugZap />
                            {testing ? 'A testar…' : 'Testar ligação'}
                        </Button>
                    </>
                }
            />

            <Tabs value={tab} onValueChange={changeTab} className="min-w-0 gap-6">
                <TabsList variant="line" className="scroll-fade w-full justify-start overflow-x-auto border-b pb-1">
                    <TabsTrigger value="status" className="flex-none">
                        Estado
                    </TabsTrigger>
                    <TabsTrigger value="tools" className="flex-none" disabled={tools.length === 0}>
                        Ferramentas
                        <span className="font-mono text-xs text-muted-foreground tabular-nums">{tools.length}</span>
                    </TabsTrigger>
                    <TabsTrigger value="config" className="flex-none">
                        Configuração
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="status" className="flex flex-col gap-8">
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
                                <Property label="Actividade hoje">
                                    <Link href="/settings/integrations/erp/activity" className="tabular-nums underline-offset-4 hover:underline">
                                        {activity.today} {activity.today === 1 ? 'operação' : 'operações'}
                                        {activity.errors_today > 0 && <span className="text-status-danger"> · {activity.errors_today} com erro</span>}
                                    </Link>
                                </Property>
                            </div>

                            {connection?.last_error && (
                                <div className="rounded-lg border border-status-danger/30 bg-status-danger/10 px-4 py-3 font-mono text-xs break-words text-status-danger">
                                    {connection.last_error}
                                </div>
                            )}
                        </div>
                    </SettingsBlock>
                </TabsContent>

                <TabsContent value="tools" className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div className="relative w-full sm:max-w-xs">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Procurar ferramenta"
                                aria-label="Procurar ferramenta"
                                className="pl-8"
                            />
                        </div>
                        <NativeSelect
                            value={filter}
                            onChange={(e) => setFilter(e.target.value as ToolFilter)}
                            aria-label="Tipo de ferramenta"
                            className="sm:w-48"
                        >
                            <option value="all">Todas ({tools.length})</option>
                            <option value="read">Leitura ({readTools})</option>
                            <option value="write">Escrita ({writeTools})</option>
                        </NativeSelect>
                    </div>

                    {shown.length === 0 ? (
                        <EmptyState icon={Search} title="Nenhuma ferramenta" description="Nenhuma ferramenta do ERP corresponde à pesquisa." />
                    ) : (
                        <div className="overflow-hidden rounded-xl border bg-card">
                            <ul className="divide-y text-sm">
                                {paged.items.map((tool) => (
                                    <li key={tool.name} className="flex min-w-0 items-start justify-between gap-3 px-4 py-2.5">
                                        <div className="min-w-0 flex-1">
                                            <p className="flex min-w-0 items-baseline gap-2">
                                                <span className="truncate">{tool.title ?? tool.name}</span>
                                                <span className="hidden truncate font-mono text-xs text-muted-foreground sm:inline">{tool.name}</span>
                                            </p>
                                            {tool.description && <p className="line-clamp-2 text-xs text-muted-foreground">{tool.description}</p>}
                                        </div>
                                        <StatusBadge tone={tool.read_only ? 'idle' : 'warning'} dot={false}>
                                            {tool.read_only ? 'leitura' : 'escrita'}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                            <PaginationBar {...paged.pager} noun={['ferramenta', 'ferramentas']} className="border-t px-4 py-2" />
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="config">
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
                                        <option value="local">Servidor de demonstração</option>
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
                </TabsContent>
            </Tabs>
        </SettingsLayout>
    );
}
