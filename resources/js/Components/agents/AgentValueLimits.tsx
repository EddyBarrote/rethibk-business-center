import { useForm, usePage } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import type { FormEvent } from 'react';

import { FormSection } from '@/Components/agents/FormParts';
import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';

/** The agent's value limit (docs/DECISOES.md, "Limite de valor por agente"), from AgentDefinitionController::edit. */
export interface AgentValueLimitData {
    /** The agent's own; null takes the company's, 0 is no limit. */
    amount: number | null;
    /** Its own per type (purchases, sales, expenses); null takes the agent's. */
    types: Record<string, number | null>;
    /** The company's default (business.agent_value_limit_mzn); 0 is none. */
    company: number;
    labels: Record<string, string>;
    /** Types the agent can commit value in with its capabilities. */
    held: string[];
    max: number;
}

const words = (amount: number) => (amount > 0 ? `${number(amount)} MZN` : 'sem limite');
const field = (value: number | null) => (value === null ? '' : String(value));
const parsed = (value: string) => (value.trim() === '' ? null : Number(value));

/**
 * Its own form, beside the agent's definition: what an invoice, order, quote
 * or expense of this agent may be worth before a person decides.
 */
export default function AgentValueLimits({ agentId }: { agentId: number }) {
    const limits = usePage<{ value_limits?: AgentValueLimitData }>().props.value_limits;
    const form = useForm({
        amount: field(limits?.amount ?? null),
        types: Object.fromEntries(Object.entries(limits?.types ?? {}).map(([type, value]) => [type, field(value)])) as Record<string, string>,
    });

    if (!limits) {
        return null;
    }

    const errors = form.errors as Record<string, string | undefined>;
    const own = parsed(form.data.amount);
    const general = own ?? limits.company;
    const effective = (type: string) => parsed(form.data.types[type] ?? '') ?? general;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            amount: parsed(data.amount),
            types: Object.fromEntries(Object.entries(data.types).map(([type, value]) => [type, parsed(value)])),
        }));
        form.put(`/agents/${agentId}/value-limit`, { preserveScroll: true });
    };

    return (
        <div className="grid gap-8 border-t border-border/60 pt-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div aria-hidden className="hidden lg:block" />
            <form onSubmit={submit} className="min-w-0">
                <FormSection
                    id="limite-valor"
                    title="Limite de valor"
                    description="Facturas, orçamentos, propostas, encomendas e despesas deste agente acima do limite (em MZN, sem impostos, por documento), sem valor indicado ou noutra moeda esperam por uma pessoa, seja qual for o nível ou uma regra «Permitir sempre». Pagamentos, notas de crédito e contratos decide sempre uma pessoa."
                    footer={
                        <>
                            {form.isDirty && <span className="hidden text-xs text-muted-foreground sm:inline">Há alterações por guardar.</span>}
                            <Button type="submit" variant="outline" disabled={form.processing}>
                                <Banknote />
                                Guardar limite
                            </Button>
                        </>
                    }
                >
                    <div className="grid gap-6">
                        <Field
                            id="value_limit_amount"
                            label="Limite do agente (MZN)"
                            error={errors.amount}
                            hint={`Vazio usa o da empresa (${words(limits.company)}). 0 tira o limite a este agente.`}
                        >
                            <Input
                                id="value_limit_amount"
                                type="number"
                                min="0"
                                max={limits.max}
                                step="1"
                                inputMode="numeric"
                                className="max-w-56 font-mono"
                                placeholder={limits.company > 0 ? String(limits.company) : 'Sem limite'}
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                        </Field>

                        <fieldset className="grid gap-3">
                            <legend className="mb-1 text-sm font-medium">Por tipo (opcional)</legend>
                            <p className="text-xs text-muted-foreground">Vazio usa o limite do agente. Serve, por exemplo, para deixar compras maiores do que despesas.</p>
                            <div className="grid gap-4 sm:grid-cols-3">
                                {Object.entries(limits.labels).map(([type, label]) => (
                                    <Field
                                        key={type}
                                        id={`value_limit_${type}`}
                                        label={label}
                                        error={errors[`types.${type}`]}
                                        hint={limits.held.includes(type) ? `Em vigor: ${words(effective(type))}.` : 'O agente não tem capacidades deste tipo.'}
                                    >
                                        <Input
                                            id={`value_limit_${type}`}
                                            type="number"
                                            min="0"
                                            max={limits.max}
                                            step="1"
                                            inputMode="numeric"
                                            className="font-mono"
                                            placeholder={general > 0 ? String(general) : 'Sem limite'}
                                            value={form.data.types[type] ?? ''}
                                            onChange={(e) => form.setData('types', { ...form.data.types, [type]: e.target.value })}
                                        />
                                    </Field>
                                ))}
                            </div>
                        </fieldset>
                    </div>
                </FormSection>
            </form>
        </div>
    );
}
