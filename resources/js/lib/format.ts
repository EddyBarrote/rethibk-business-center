const dateTimeFormat = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
const dateFormat = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });
const timeFormat = new Intl.DateTimeFormat('pt-PT', { timeStyle: 'medium' });
/*
 * One pt-PT formatter for the whole console: money with two decimals in lists
 * (four only where a single run's cost is read), durations with a decimal comma,
 * dates as 30/10/2026 and times as 30/10/26, 10:00.
 */
const usdFormat = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const usdPreciseFormat = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 });
const mznFormat = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'MZN', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const numberFormat = new Intl.NumberFormat('pt-PT');
const secondsFormat = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const dateTime = (value: string | null | undefined) => (value ? dateTimeFormat.format(new Date(value)) : '—');
export const date = (value: string | null | undefined) => (value ? dateFormat.format(new Date(value)) : '—');
export const time = (value: string | null | undefined) => (value ? timeFormat.format(new Date(value)) : '—');
const monthFormat = new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' });
/** A due date: the day alone when it has no time of day (midnight), day and time otherwise. */
export const deadline = (value: string | null | undefined) => {
    if (!value) {
        return '—';
    }
    const at = new Date(value);

    return at.getHours() === 0 && at.getMinutes() === 0 ? dateFormat.format(at) : dateTimeFormat.format(at);
};
/** ISO days and months inside a text ("2026-09-01 a 2026-09-30", "2026-10") written the pt-PT way. */
export const period = (text: string | null | undefined) =>
    (text ?? '')
        .replace(/\b\d{4}-\d{2}-\d{2}\b/g, (day) => dateFormat.format(new Date(`${day}T00:00`)))
        .replace(/\b(\d{4})-(\d{2})\b/g, (_, year: string, month: string) => monthFormat.format(new Date(Number(year), Number(month) - 1, 1)));
/** "0,04 US$"; an amount that rounds to zero but is not zero reads "< 0,01 US$". */
export const usd = (value: number | null | undefined) =>
    value === null || value === undefined ? '—' : value > 0 && value < 0.005 ? `< ${usdFormat.format(0.01)}` : usdFormat.format(value);
/** "0,0419 US$": the cost of one run, where cents of a cent matter. */
export const usdPrecise = (value: number | null | undefined) => (value === null || value === undefined ? '—' : usdPreciseFormat.format(value));
export const number = (value: number | null | undefined) => (value === null || value === undefined ? '—' : numberFormat.format(value));
/** "350 ms", "12,1 s", "2 min 05 s". */
export const duration = (ms: number | null | undefined) => {
    if (ms === null || ms === undefined) {
        return '—';
    }
    if (ms < 1000) {
        return `${Math.round(ms)} ms`;
    }
    if (ms < 60_000) {
        return `${secondsFormat.format(ms / 1000)} s`;
    }

    const minutes = Math.floor(ms / 60_000);

    return `${minutes} min ${String(Math.round((ms % 60_000) / 1000)).padStart(2, '0')} s`;
};
/** "1 agente", "3 agentes": plurals written out, never "agente(s)". */
export const plural = (count: number, one: string, many: string) => `${numberFormat.format(count)} ${count === 1 ? one : many}`;
export const mzn = (value: number | null | undefined) => (value === null || value === undefined ? '—' : mznFormat.format(value));

const relativeFormat = new Intl.RelativeTimeFormat('pt-PT', { numeric: 'auto', style: 'short' });
const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/** "há 5 min", "ontem": for timestamps in dense lists, with the exact time in a title. */
export const ago = (value: string | null | undefined) => {
    if (!value) {
        return '—';
    }

    const seconds = (new Date(value).getTime() - Date.now()) / 1000;
    const unit = units.find(([, size]) => Math.abs(seconds) >= size);

    return unit ? relativeFormat.format(Math.round(seconds / unit[1]), unit[0]) : 'agora';
};

export const compact = (value: number | null | undefined) =>
    value === null || value === undefined ? '—' : new Intl.NumberFormat('pt-PT', { notation: 'compact' }).format(value);

/** "1,2 MB" for file sizes. */
export const bytes = (value: number | null | undefined) => {
    if (value === null || value === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(Math.max(value, 1)) / Math.log(1024)), units.length - 1);

    return `${new Intl.NumberFormat('pt-PT', { maximumFractionDigits: exponent === 0 ? 0 : 1 }).format(value / 1024 ** exponent)} ${units[exponent]}`;
};

/* Names for the fields agents extract from emails and documents; anything else reads as words. */
const fieldNames: Record<string, string> = {
    account_id: 'Cliente',
    amount: 'Valor',
    bcc: 'Cópia oculta',
    body: 'Texto',
    budget: 'Orçamento',
    category: 'Categoria',
    cc: 'Cópia',
    city: 'Cidade',
    company: 'Empresa',
    company_name: 'Empresa',
    contact_email: 'Email de contacto',
    contact_name: 'Contacto',
    contact_phone: 'Telefone',
    currency: 'Moeda',
    date: 'Data',
    deadline: 'Prazo',
    delivery_days: 'Prazo de entrega (dias)',
    department: 'Departamento',
    description: 'Descrição',
    due_date: 'Vencimento',
    email: 'Email',
    end_date: 'Fim',
    estimated_value: 'Valor estimado',
    expense_id: 'Despesa',
    filename: 'Ficheiro',
    invoice_id: 'Factura',
    invoice_number: 'N.º da factura',
    issue_date: 'Data de emissão',
    items: 'Itens',
    lead_id: 'Oportunidade',
    lines: 'Linhas',
    manager: 'Gestor',
    name: 'Nome',
    notes: 'Notas',
    nuit: 'NUIT',
    opening_id: 'Vaga',
    payment_terms_days: 'Prazo de pagamento (dias)',
    period: 'Período',
    phone: 'Telefone',
    po_id: 'Nota de encomenda',
    position: 'Função',
    project_id: 'Projecto',
    quote_id: 'Cotação',
    reason: 'Motivo',
    recommendation: 'Recomendação',
    reference: 'Referência',
    rfq_id: 'Pedido de cotação',
    role: 'Função',
    score: 'Pontuação',
    sector: 'Sector',
    source: 'Origem',
    start_date: 'Início',
    status: 'Estado',
    subject: 'Assunto',
    summary: 'Resumo',
    supplier: 'Fornecedor',
    supplier_id: 'Fornecedor',
    supplier_ids: 'Fornecedores',
    title: 'Título',
    to: 'Para',
    total: 'Total',
    valid_until: 'Válida até',
    vat: 'IVA',
};

export const fieldLabel = (key: string) => fieldNames[key] ?? key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');

/**
 * Markdown as one line of plain text, for previews in lists and notifications:
 * no "###", "**", "---" or table pipes in front of a person.
 */
export const plainText = (markdown: string | null | undefined) =>
    (markdown ?? '')
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/gm, '')
        .replace(/^\s*([-*_]\s*){3,}$/gm, ' ')
        .replace(/^\s*\|?(\s*:?-{2,}:?\s*\|)+\s*:?-*:?\s*$/gm, ' ')
        .replace(/\|/g, ' ')
        .replace(/(\*\*|~~|`)(?=\S)([\s\S]*?\S)\1/g, '$2')
        // Underscores emphasise only between words, so "list_receivables" keeps its own.
        .replace(/(^|\W)(__?)(?=\S)([^_]*?\S)\2(?=\W|$)/g, '$1$3')
        .replace(/(^|[^*])\*(?=\S)([^*]*?\S)\*/g, '$1$2')
        .replace(/[*`]/g, '')
        .replace(/\s+/g, ' ')
        .trim();

/**
 * What a run was asked, without the tags the platform puts in front of the prompt
 * ("[Proprietário] [Nota da plataforma] …"): the person is shown on its own, and a
 * platform note becomes the label.
 */
/** The prompt of a run without the leading "[Proprietário] [Nota da plataforma]" tags. */
export const withoutTags = (input: string) => input.trim().replace(/^(\[[^\]]{1,60}\]\s*)+/, '');

/** Capability keys an agent's brief mentions ("erp.invoices.list_receivables") read as their names. */
export const withToolNames = (text: string, names: Record<string, string> | undefined) =>
    names ? text.replace(/\b[a-z]+(?:\.[a-z_]+)+\b/g, (key) => names[key] ?? names[`erp.${key}`] ?? key) : text;

export const runTitle = (input: string, names?: Record<string, string>) => {
    let rest = withToolNames(input.trim(), names);
    const tags: string[] = [];
    let match: RegExpMatchArray | null;

    while ((match = rest.match(/^\[([^\]]{1,60})\]\s*/))) {
        tags.push(match[1]);
        rest = rest.slice(match[0].length);
    }

    // A task handed to an agent reads as the task, not as the brief the agent got.
    const task = rest.match(/^Foi-te atribuída a tarefa (\S+): ([^\n]+)/);
    if (task) {
        return `${task[1]} · ${plainText(task[2])}`;
    }

    return plainText(rest) || tags.join(' · ') || input;
};
