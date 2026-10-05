const dateTimeFormat = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
const dateFormat = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });
const timeFormat = new Intl.DateTimeFormat('pt-PT', { timeStyle: 'medium' });
const usdFormat = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 });
const mznFormat = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'MZN', maximumFractionDigits: 2 });

export const dateTime = (value: string | null | undefined) => (value ? dateTimeFormat.format(new Date(value)) : '—');
export const date = (value: string | null | undefined) => (value ? dateFormat.format(new Date(value)) : '—');
export const time = (value: string | null | undefined) => (value ? timeFormat.format(new Date(value)) : '—');
export const usd = (value: number | null | undefined) => (value === null || value === undefined ? '—' : usdFormat.format(value));
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
    amount: 'Valor',
    company: 'Empresa',
    company_name: 'Empresa',
    contact_email: 'Email de contacto',
    contact_name: 'Contacto',
    contact_phone: 'Telefone',
    currency: 'Moeda',
    date: 'Data',
    deadline: 'Prazo',
    description: 'Descrição',
    due_date: 'Vencimento',
    email: 'Email',
    estimated_value: 'Valor estimado',
    invoice_number: 'N.º da factura',
    issue_date: 'Data de emissão',
    name: 'Nome',
    notes: 'Notas',
    nuit: 'NUIT',
    phone: 'Telefone',
    position: 'Função',
    project_id: 'Projecto',
    reference: 'Referência',
    source: 'Origem',
    title: 'Título',
    total: 'Total',
    vat: 'IVA',
};

export const fieldLabel = (key: string) => fieldNames[key] ?? key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');
