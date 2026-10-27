import { Head, router } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { ConfirmDialog } from '@/Components/Dialogs';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Provider {
    key: string;
    label: string;
    models: string[];
    own: { last_four: string; by: string | null; at: string } | null;
    from_env: boolean;
}

/**
 * Definições › Provedores de IA (docs/DECISOES.md, "Chaves dos provedores de
 * IA na plataforma"): the company's key for each provider, pasted here and
 * kept encrypted; the page never shows it back, only its last characters.
 */
export default function AiProviders({ providers }: { providers: Provider[] }) {
    return (
        <SettingsLayout>
            <Head title="Provedores de IA" />
            <PageHeader
                title="Provedores de IA"
                description="As chaves com que os agentes falam com cada provedor. Ficam cifradas e nunca voltam a ser mostradas, só os últimos caracteres. Com uma chave aqui, os modelos desse provedor podem ser escolhidos em cada agente."
            />
            <ul className="flex flex-col">
                {providers.map((provider) => (
                    <ProviderRow key={provider.key} provider={provider} />
                ))}
            </ul>
        </SettingsLayout>
    );
}

function ProviderRow({ provider }: { provider: Provider }) {
    const [editing, setEditing] = useState(false);
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [removing, setRemoving] = useState(false);
    const connected = provider.own !== null || provider.from_env;

    const save = (event: FormEvent) => {
        event.preventDefault();
        router.put(
            `/settings/ai-providers/${provider.key}`,
            { key },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onSuccess: () => {
                    setKey('');
                    setEditing(false);
                    setError(null);
                },
                onError: (errors) => setError(errors.key ?? 'Não foi possível guardar.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <li className="flex flex-col gap-3 border-t border-border/60 py-5 first:border-t-0 sm:flex-row sm:items-start">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <span
                    className={cn(
                        'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                        connected ? 'bg-status-success/12 text-status-success' : 'bg-muted text-muted-foreground',
                    )}
                >
                    {connected ? <CheckCircle2 className="size-4" /> : <KeyRound className="size-4" />}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{provider.label}</p>
                    <p className="text-xs text-muted-foreground">
                        {provider.own
                            ? `Chave da empresa ••••${provider.own.last_four}${provider.own.by ? `, guardada por ${provider.own.by}` : ''} ${ago(provider.own.at)}`
                            : provider.from_env
                              ? 'Ligado pela chave do servidor (.env).'
                              : 'Sem chave: os modelos deste provedor não se podem escolher.'}
                    </p>
                    {provider.models.length > 0 && <p className="mt-1 text-xs text-muted-foreground/80">Modelos: {provider.models.join(', ')}</p>}
                    {editing && (
                        <form onSubmit={save} className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div className="flex-1">
                                <Input
                                    type="password"
                                    autoFocus
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={key}
                                    onChange={(event) => setKey(event.target.value)}
                                    placeholder="Cole aqui a chave da API"
                                    aria-label={`Chave de ${provider.label}`}
                                    className="font-mono"
                                />
                                {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
                            </div>
                            <div className="flex gap-2">
                                <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                                    Cancelar
                                </Button>
                                <Button type="submit" disabled={busy || key.trim().length < 10}>
                                    Guardar
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
            {!editing && (
                <div className="flex shrink-0 gap-2 pl-11 sm:pl-0">
                    <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                        {provider.own ? 'Substituir chave' : 'Colar chave'}
                    </Button>
                    {provider.own && (
                        <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={() => setRemoving(true)}>
                            <Trash2 />
                            Retirar
                        </Button>
                    )}
                </div>
            )}
            <ConfirmDialog
                open={removing}
                onOpenChange={setRemoving}
                title={`Retirar a chave de ${provider.label}?`}
                description={
                    provider.from_env
                        ? 'Os agentes voltam a usar a chave do servidor (.env).'
                        : 'Os agentes que usam modelos deste provedor deixam de funcionar até haver outra chave.'
                }
                confirmLabel="Retirar"
                destructive
                onConfirm={() =>
                    router.delete(`/settings/ai-providers/${provider.key}`, { preserveScroll: true, onFinish: () => setRemoving(false) })
                }
            />
        </li>
    );
}
