import { Link, router, useForm } from '@inertiajs/react';
import { KeyRound, PlugZap, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { FormSection } from '@/Components/agents/FormParts';
import { ConfirmDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ago, dateTime } from '@/lib/format';

/*
 * An agent's own identity in the ERP (docs/DECISOES.md, "Identidade própria de
 * cada agente no ERP"): a token of the ERP's MCP server that acts as the
 * agent's own staff member there, with only its tools ticked. The token is
 * written here and never comes back from the server.
 */

export interface ErpIdentityData {
    has_token: boolean;
    set_at: string | null;
    /** The tenant's ERP is reached over HTTP, where a token says who calls; the local demo ERP has no identities. */
    applies: boolean;
    connection: { name: string; transport: string } | null;
    /** ERP tools behind the agent's switched-on ERP capabilities. */
    erp_tools: string[];
    test: {
        at: string;
        ok: boolean;
        error: string | null;
        count: number;
        tools: string[];
        whoami: boolean;
        staff: { id: number | null; name: string | null; email: string | null } | null;
        missing: string[];
    } | null;
    last_refusal: { tool: string; reason: string; agent_run_id: number | null; at: string } | null;
}

export default function AgentErpIdentity({ identity, base, runHref }: { identity: ErpIdentityData; base: string; runHref?: (id: number) => string }) {
    const form = useForm({ token: '' });
    const [testing, setTesting] = useState(false);
    const action = `${base}/erp-identity`;

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(action, { preserveScroll: true, preserveState: true, onSuccess: () => form.reset() });
    };

    const test = () =>
        router.post(
            `${action}/test`,
            {},
            { preserveScroll: true, preserveState: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) },
        );

    return (
        <FormSection
            id="erp"
            title="Identidade no ERP"
            description="Com um token próprio, o que este agente faz no ERP fica em nome do utilizador do ERP dele, e só com as ferramentas marcadas nesse token. Sem token, usa o da ligação ao ERP, igual para todos os agentes."
        >
            <div className="grid gap-5">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    {identity.has_token ? (
                        <StatusBadge tone="success">Identidade própria</StatusBadge>
                    ) : (
                        <StatusBadge tone="idle">Usa o token da ligação ao ERP</StatusBadge>
                    )}
                    {identity.has_token && identity.set_at && (
                        <span className="text-muted-foreground" title={dateTime(identity.set_at)}>
                            token guardado {ago(identity.set_at)}
                        </span>
                    )}
                </div>

                {!identity.applies && (
                    <p className="rounded-lg border border-status-warning/30 bg-status-warning/10 px-3 py-2 text-sm">
                        {identity.connection
                            ? 'A ligação ao ERP usa o servidor de demonstração local, que não tem identidades: o token deste agente só conta quando a ligação usar o servidor MCP do ERP.'
                            : 'Ainda não há ligação ao ERP configurada (Definições › Ligação ao ERP).'}
                    </p>
                )}

                <form onSubmit={save} className="grid gap-3">
                    <Field
                        id="erp-token"
                        label="Token do ERP deste agente"
                        error={form.errors.token}
                        hint="No ERP: Setup › MCP Server › novo token, com o utilizador do ERP deste agente e só as ferramentas dele. Depois de guardado, o token não volta a aparecer aqui."
                    >
                        <Input
                            id="erp-token"
                            type="password"
                            autoComplete="off"
                            className="font-mono"
                            placeholder={identity.has_token ? 'Guardado. Escreva um novo para o substituir.' : 'Cole aqui o token criado no ERP'}
                            value={form.data.token}
                            onChange={(e) => form.setData('token', e.target.value)}
                            aria-invalid={!!form.errors.token}
                        />
                    </Field>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {identity.has_token && (
                            <ConfirmDialog
                                title="Remover o token deste agente?"
                                description="O agente volta a usar o token da ligação ao ERP: o que fizer no ERP fica em nome desse utilizador, com as ferramentas dele. O token continua válido no ERP até o revogar lá."
                                confirmLabel="Remover token"
                                destructive
                                onConfirm={() => router.delete(action, { preserveScroll: true, preserveState: true })}
                                trigger={
                                    <Button type="button" variant="ghost" size="sm" className="text-muted-foreground hover:text-status-danger">
                                        <Trash2 />
                                        Remover token
                                    </Button>
                                }
                            />
                        )}
                        {identity.has_token && (
                            <Button type="button" variant="outline" size="sm" disabled={!identity.applies || testing} onClick={test}>
                                <PlugZap />
                                {testing ? 'A testar…' : 'Testar identidade no ERP'}
                            </Button>
                        )}
                        <Button type="submit" size="sm" disabled={form.processing || form.data.token.trim() === ''}>
                            <KeyRound />
                            {identity.has_token ? 'Substituir token' : 'Guardar token'}
                        </Button>
                    </div>
                </form>

                {identity.has_token && identity.test && <TestResult test={identity.test} needed={identity.erp_tools} />}

                {identity.last_refusal && (
                    <div className="rounded-lg border border-status-danger/30 bg-status-danger/5 px-3 py-2 text-sm">
                        <p className="font-medium">
                            Última recusa do ERP{' '}
                            <span className="font-normal text-muted-foreground" title={dateTime(identity.last_refusal.at)}>
                                {ago(identity.last_refusal.at)}
                            </span>
                        </p>
                        <p className="mt-1">{identity.last_refusal.reason}</p>
                        {identity.last_refusal.agent_run_id !== null && runHref && (
                            <Link href={runHref(identity.last_refusal.agent_run_id)} className="mt-1 inline-block text-primary underline">
                                Ver a execução
                            </Link>
                        )}
                    </div>
                )}
            </div>
        </FormSection>
    );
}

function TestResult({ test, needed }: { test: NonNullable<ErpIdentityData['test']>; needed: string[] }) {
    if (!test.ok) {
        return (
            <div className="rounded-lg border border-status-danger/30 bg-status-danger/5 px-3 py-2 text-sm">
                <p className="font-medium">
                    O teste falhou{' '}
                    <span className="font-normal text-muted-foreground" title={dateTime(test.at)}>
                        {ago(test.at)}
                    </span>
                </p>
                <p className="mt-1">{test.error}</p>
            </div>
        );
    }

    const staff = test.staff;

    return (
        <div className="grid gap-2 rounded-lg bg-muted/40 px-3 py-3 text-sm">
            <p className="text-xs text-muted-foreground" title={dateTime(test.at)}>
                Testado {ago(test.at)}
            </p>
            <p>
                {staff?.name ? (
                    <>
                        Age no ERP como <span className="font-medium">{staff.name}</span>
                        {staff.email && <span className="text-muted-foreground"> · {staff.email}</span>}
                        {staff.id !== null && <span className="text-muted-foreground"> · utilizador n.º {staff.id}</span>}
                    </>
                ) : test.whoami ? (
                    'O ERP não disse em nome de quem o token age (whoami não respondeu).'
                ) : (
                    'Este ERP não tem a ferramenta whoami: não diz em nome de quem o token age. Confirme no ERP, em Setup › MCP Server.'
                )}
            </p>
            <p>
                O token vê <span className="font-medium">{test.count}</span> {test.count === 1 ? 'ferramenta' : 'ferramentas'} do ERP.{' '}
                {needed.length === 0
                    ? 'O agente ainda não tem capacidades do ERP.'
                    : test.missing.length === 0
                      ? `Cobrem as ${needed.length} ${needed.length === 1 ? 'ferramenta' : 'ferramentas'} do ERP que este agente usa.`
                      : `Faltam ${test.missing.length} das ${needed.length} ferramentas do ERP que este agente usa; marque-as no token, no ERP, ou tire-lhe essas capacidades.`}
            </p>
            {test.missing.length > 0 && (
                <ul className="flex flex-wrap gap-1.5">
                    {test.missing.map((tool) => (
                        <li key={tool}>
                            <StatusBadge tone="danger" dot={false} className="font-mono">
                                {tool}
                            </StatusBadge>
                        </li>
                    ))}
                </ul>
            )}
            {test.tools.length > 0 && (
                <details className="text-xs text-muted-foreground">
                    <summary className="cursor-pointer">Ferramentas que o token vê</summary>
                    <p className="mt-1 font-mono leading-relaxed">{test.tools.join(', ')}</p>
                </details>
            )}
        </div>
    );
}
