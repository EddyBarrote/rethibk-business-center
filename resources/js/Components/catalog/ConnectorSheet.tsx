import { useForm } from '@inertiajs/react';
import { type FormEvent, useEffect } from 'react';

import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';

export interface ConnectorData {
    id: number;
    key: string;
    name: string;
    description: string | null;
    kind: 'mcp' | 'http';
    kind_label: string;
    url: string;
    has_secret: boolean;
    http_method: string | null;
    input_schema: Record<string, unknown> | null;
    is_mutating: boolean;
    tools: number;
    last_error: string | null;
    last_synced_at: string | null;
    is_active: boolean;
}

const exampleSchema = `{
  "type": "object",
  "properties": {
    "currency": { "type": "string", "description": "Código ISO, ex.: USD" }
  },
  "required": ["currency"]
}`;

/**
 * Create or edit a connector: a remote MCP server (each of its tools becomes a
 * capability) or a single HTTP action. Used by the company's admins and by
 * the super admin for global connectors.
 */
export function ConnectorSheet({
    base,
    connector,
    open,
    onOpenChange,
    scope,
}: {
    base: string;
    connector: ConnectorData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'tenant' | 'global';
}) {
    const blank = {
        key: '',
        name: '',
        description: '',
        kind: 'mcp' as 'mcp' | 'http',
        url: '',
        secret: '',
        clear_secret: false,
        http_method: 'POST',
        input_schema: '',
        is_mutating: false,
        is_active: true,
    };
    const form = useForm(blank);

    useEffect(() => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.setData(
            connector
                ? {
                      key: connector.key,
                      name: connector.name,
                      description: connector.description ?? '',
                      kind: connector.kind,
                      url: connector.url,
                      secret: '',
                      clear_secret: false,
                      http_method: connector.http_method ?? 'POST',
                      input_schema: connector.input_schema ? JSON.stringify(connector.input_schema, null, 2) : '',
                      is_mutating: connector.is_mutating,
                      is_active: connector.is_active,
                  }
                : blank,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, connector?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, http_method: data.kind === 'http' ? data.http_method : null }));
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (connector) {
            form.put(`${base}/${connector.id}`, options);
        } else {
            form.post(base, options);
        }
    };

    const http = form.data.kind === 'http';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>{connector ? connector.name : scope === 'global' ? 'Novo conector global' : 'Novo conector'}</SheetTitle>
                        <SheetDescription>
                            {scope === 'global'
                                ? 'Fica disponível para todas as organizações; cada uma decide se o activa.'
                                : 'As ferramentas dele passam a ser capacidades desta organização, que depois atribui a agentes.'}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="grid gap-4 px-4">
                        <div className="grid gap-2">
                            <Label>Tipo</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {(['mcp', 'http'] as const).map((kind) => (
                                    <button
                                        key={kind}
                                        type="button"
                                        onClick={() => form.setData('kind', kind)}
                                        className={`rounded-lg border px-3 py-2 text-left text-sm transition-colors ${form.data.kind === kind ? 'border-primary bg-primary/5' : 'hover:bg-accent/60'}`}
                                    >
                                        <span className="block font-medium">{kind === 'mcp' ? 'Servidor MCP' : 'Pedido HTTP'}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {kind === 'mcp' ? 'Todas as ferramentas do servidor.' : 'Uma acção numa API.'}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="c-name" label="Nome" error={form.errors.name}>
                                <Input id="c-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </Field>
                            <Field id="c-key" label="Chave" error={form.errors.key} hint="Letras, números e hífen.">
                                <Input id="c-key" className="font-mono" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} required />
                            </Field>
                        </div>
                        <Field
                            id="c-description"
                            label={http ? 'O que faz (o agente decide por isto)' : 'Descrição'}
                            error={form.errors.description}
                        >
                            <Textarea id="c-description" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} required />
                        </Field>
                        <div className={http ? 'grid gap-4 sm:grid-cols-[7rem_1fr]' : 'grid gap-4'}>
                            {http && (
                                <Field id="c-method" label="Método" error={form.errors.http_method}>
                                    <NativeSelect id="c-method" value={form.data.http_method} onChange={(e) => form.setData('http_method', e.target.value)}>
                                        {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((method) => (
                                            <option key={method}>{method}</option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            )}
                            <Field id="c-url" label="Endereço" error={form.errors.url} hint={scope === 'tenant' ? 'https, num endereço público.' : undefined}>
                                <Input
                                    id="c-url"
                                    className="font-mono"
                                    placeholder={http ? 'https://api.exemplo.co.mz/cotacoes' : 'https://mcp.exemplo.co.mz/mcp'}
                                    value={form.data.url}
                                    onChange={(e) => form.setData('url', e.target.value)}
                                    required
                                />
                            </Field>
                        </div>
                        <Field
                            id="c-secret"
                            label="Token (Bearer)"
                            error={form.errors.secret}
                            hint={connector?.has_secret ? 'Há um token guardado. Deixe vazio para o manter.' : 'Opcional. Guardado cifrado, nunca volta ao browser.'}
                        >
                            <Input
                                id="c-secret"
                                type="password"
                                autoComplete="off"
                                value={form.data.secret}
                                onChange={(e) => form.setData('secret', e.target.value)}
                            />
                        </Field>
                        {connector?.has_secret && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.clear_secret} onCheckedChange={(on) => form.setData('clear_secret', on === true)} />
                                Apagar o token guardado
                            </label>
                        )}
                        {http && (
                            <>
                                <Field
                                    id="c-schema"
                                    label="Argumentos (JSON Schema)"
                                    error={form.errors.input_schema}
                                    hint="O que o agente envia. Em GET vai na query string; nos outros métodos, como JSON."
                                >
                                    <Textarea
                                        id="c-schema"
                                        rows={8}
                                        className="font-mono text-xs"
                                        placeholder={exampleSchema}
                                        value={form.data.input_schema}
                                        onChange={(e) => form.setData('input_schema', e.target.value)}
                                    />
                                </Field>
                                <label className="flex items-start gap-3 rounded-lg border p-3">
                                    <Switch checked={form.data.is_mutating} onCheckedChange={(on) => form.setData('is_mutating', on)} className="mt-0.5" />
                                    <span className="text-sm">
                                        <span className="block font-medium">Altera dados</span>
                                        <span className="text-muted-foreground">Cria, envia ou apaga algo. Passa pelo portão de autonomia e, por omissão, pede aprovação.</span>
                                    </span>
                                </label>
                            </>
                        )}
                        <label className="flex items-center gap-3">
                            <Switch checked={form.data.is_active} onCheckedChange={(on) => form.setData('is_active', on)} />
                            <span className="text-sm">Ligado</span>
                        </label>
                        {connector?.last_error && (
                            <p className="rounded-lg border border-status-danger/30 bg-status-danger/10 px-3 py-2 font-mono text-xs text-status-danger">{connector.last_error}</p>
                        )}
                    </div>

                    <SheetFooter className="mt-auto">
                        <Button type="submit" disabled={form.processing}>
                            {connector ? 'Guardar e ler ferramentas' : 'Criar e ler ferramentas'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
