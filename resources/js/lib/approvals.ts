import { date, fieldLabel, mzn } from '@/lib/format';

/*
 * What an approval asks for, in words a person reads: one sentence per ERP
 * action instead of the tool call ("Expenses Classify (category: epi, …)"),
 * and its arguments as labelled, formatted facts. Local capabilities already
 * write their summary in Portuguese ("Enviar email a …"), so theirs is kept.
 */

type Payload = Record<string, unknown>;

const text = (value: unknown) => (value === null || value === undefined || value === '' ? null : String(value));
const money = (value: unknown) =>
    typeof value === 'number' || (typeof value === 'string' && value.trim() !== '' && !isNaN(Number(value))) ? mzn(Number(value)) : text(value);
const quoted = (value: unknown) => (text(value) ? `«${text(value)}»` : null);

/** Joins the parts that exist: "Registar despesa de 72 848,00 MZN · Segurança Total EPI". */
const join = (...parts: (string | null | false | undefined)[]) => parts.filter(Boolean).join(' · ');

const recipients = (p: Payload) => (Array.isArray(p.to) ? p.to.map(String).join(', ') : text(p.to));

const titles: Record<string, (p: Payload) => string> = {
    // The subject names the client and the matter; the address goes to the second line (approvalRecipient).
    'comms.send_email': (p) => (text(p.subject) ? `Enviar email «${text(p.subject)}»` : `Enviar email a ${recipients(p) ?? '—'}`),
    'erp.expenses.create': (p) =>
        join(`Registar despesa${p.amount !== undefined ? ` de ${money(p.amount)}` : ''}`, text(p.supplier), text(p.project_id)),
    'erp.expenses.classify': (p) =>
        join(`Classificar a despesa ${text(p.expense_id) ?? ''} como ${text(p.category)?.toUpperCase() ?? '—'}`.trim(), text(p.project_id)),
    'erp.leads.create': (p) =>
        join(
            `Registar a oportunidade ${quoted(p.title) ?? ''}`.trim(),
            text(p.company_name) ?? text(p.account_id),
            p.estimated_value !== undefined && money(p.estimated_value),
        ),
    'erp.leads.update': (p) => join(`Actualizar a oportunidade ${text(p.lead_id) ?? ''}`.trim(), text(p.status) && `estado ${text(p.status)}`),
    'erp.leads.attach_document': (p) => `Juntar ${quoted(p.filename) ?? 'um documento'} à oportunidade ${text(p.lead_id) ?? ''}`.trim(),
    'erp.crm.create_contact': (p) => join(`Criar o contacto ${text(p.name) ?? ''}`.trim(), text(p.account_id) && `cliente ${text(p.account_id)}`),
    'erp.crm.update_account': (p) => `Actualizar a ficha do cliente ${text(p.account_id) ?? ''}`.trim(),
    'erp.invoices.create_draft': (p) =>
        join('Preparar rascunho de factura', text(p.account_id) && `cliente ${text(p.account_id)}`, text(p.project_id)),
    'erp.invoices.issue': (p) => `Emitir a factura ${text(p.invoice_id) ?? ''}`.trim(),
    'erp.hr.create_candidate': (p) => join(`Registar a candidatura de ${text(p.name) ?? '—'}`, text(p.opening_id) && `vaga ${text(p.opening_id)}`),
    'erp.hr.create_onboarding': (p) =>
        join(
            `Abrir a integração de ${text(p.name) ?? '—'}`,
            text(p.position),
            p.start_date !== undefined && `a partir de ${date(text(p.start_date))}`,
        ),
    'erp.hr.prepare_payroll_draft': (p) => `Preparar a folha de salários de ${text(p.period) ?? '—'}`,
    'erp.procurement.create_rfq': (p) => join(`Pedir cotações ${quoted(p.title) ?? ''}`.trim(), text(p.project_id)),
    'erp.procurement.record_quote': (p) =>
        join(
            `Registar cotação${p.total !== undefined ? ` de ${money(p.total)}` : ''}`,
            text(p.supplier_id) && `fornecedor ${text(p.supplier_id)}`,
            text(p.rfq_id),
        ),
    'erp.procurement.create_po_draft': (p) => join('Preparar nota de encomenda', text(p.quote_id) && `cotação ${text(p.quote_id)}`, text(p.rfq_id)),
    'erp.procurement.receive': (p) => `Registar a recepção da encomenda ${text(p.po_id) ?? ''}`.trim(),
    'erp.projects.create': (p) =>
        join(
            `Criar o projecto ${quoted(p.name) ?? ''}`.trim(),
            text(p.account_id) && `cliente ${text(p.account_id)}`,
            p.budget !== undefined && `orçamento ${money(p.budget)}`,
        ),
    'erp.projects.update_status': (p) => join(`Mudar o estado do projecto ${text(p.project_id) ?? ''} para ${text(p.status) ?? '—'}`, text(p.reason)),
};

/** Who an email goes to, for the line under the title ("para f.abdul@agrozambeze.co.mz"). */
export function approvalRecipient(actionType: string, payload: Payload | null): string | null {
    const to = actionType === 'comms.send_email' ? recipients(payload ?? {}) : null;

    return to ? `para ${to}` : null;
}

/** The sentence that names the action. */
export function approvalTitle(actionType: string, payload: Payload | null, summary: string): string {
    const title = titles[actionType];

    return title ? title(payload ?? {}) : summary;
}

const moneyFields = new Set(['amount', 'estimated_value', 'budget', 'total', 'value', 'unit_price', 'price']);
const hiddenFields = new Set(['idempotency_key', 'approval_reference']);
const sources: Record<string, string> = {
    other: 'Outra',
    website: 'Site',
    referral: 'Indicação',
    tender: 'Concurso',
    email: 'Email',
    phone: 'Telefone',
};

/** Turns one argument into what a person reads: money in MZN, dates in pt-PT, lists joined. */
export function factValue(key: string, value: unknown): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    if (moneyFields.has(key)) {
        return money(value);
    }
    if (key === 'source' && typeof value === 'string') {
        return sources[value] ?? value;
    }
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}(T|$)/.test(value)) {
        return date(value);
    }
    if (typeof value === 'boolean') {
        return value ? 'Sim' : 'Não';
    }
    if (Array.isArray(value) && value.every((item) => typeof item !== 'object')) {
        return value.join(', ');
    }
    if (typeof value === 'object') {
        return null;
    }

    return String(value);
}

/** The arguments a person needs to decide, labelled; anything nested stays for the technical details. */
export function approvalFacts(payload: Payload | null): { label: string; value: string; long: boolean }[] {
    return Object.entries(payload ?? {})
        .filter(([key]) => !hiddenFields.has(key))
        .map(([key, value]) => ({
            label: fieldLabel(key),
            value: factValue(key, value),
            long: key === 'body' || key === 'notes' || key === 'summary',
        }))
        .filter((fact): fact is { label: string; value: string; long: boolean } => fact.value !== null);
}
