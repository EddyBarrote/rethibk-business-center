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
