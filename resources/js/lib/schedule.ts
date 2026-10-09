/*
 * A routine's schedule as people pick it (Todos os dias, Dias úteis, …) and as
 * the server stores it (cron, read in Africa/Maputo). The shapes match
 * App\Support\ScheduleLabel; anything else is edited as written.
 */

export type Frequency = 'daily' | 'weekdays' | 'weekly' | 'monthly' | 'hourly' | 'custom';

export interface SchedulePick {
    frequency: Frequency;
    /** HH:MM */
    time: string;
    /** 0 = domingo … 6 = sábado */
    days: number[];
    /** Day of the month, 1–28 */
    day: number;
    /** Minute past the hour, for hourly */
    minute: number;
    cron: string;
}

export const frequencies: { value: Frequency; label: string }[] = [
    { value: 'weekdays', label: 'Dias úteis' },
    { value: 'daily', label: 'Todos os dias' },
    { value: 'weekly', label: 'Em certos dias da semana' },
    { value: 'monthly', label: 'Uma vez por mês' },
    { value: 'hourly', label: 'De hora a hora' },
    { value: 'custom', label: 'Personalizado (cron)' },
];

export const weekdays: { value: number; short: string; plural: string }[] = [
    { value: 1, short: 'Seg', plural: 'segundas' },
    { value: 2, short: 'Ter', plural: 'terças' },
    { value: 3, short: 'Qua', plural: 'quartas' },
    { value: 4, short: 'Qui', plural: 'quintas' },
    { value: 5, short: 'Sex', plural: 'sextas' },
    { value: 6, short: 'Sáb', plural: 'sábados' },
    { value: 0, short: 'Dom', plural: 'domingos' },
];

export const defaultPick: SchedulePick = { frequency: 'weekdays', time: '08:00', days: [1], day: 1, minute: 0, cron: '0 8 * * 1-5' };

const hm = (time: string): [number, number] => {
    const [h, m] = time.split(':').map((part) => Number(part));

    return [Number.isFinite(h) ? h : 8, Number.isFinite(m) ? m : 0];
};

export function toCron(pick: SchedulePick): string {
    const [h, m] = hm(pick.time);

    switch (pick.frequency) {
        case 'daily':
            return `${m} ${h} * * *`;
        case 'weekdays':
            return `${m} ${h} * * 1-5`;
        case 'weekly':
            return `${m} ${h} * * ${[...(pick.days.length ? pick.days : [1])].sort((a, b) => a - b).join(',')}`;
        case 'monthly':
            return `${m} ${h} ${pick.day} * *`;
        case 'hourly':
            return `${pick.minute} * * * *`;
        default:
            return pick.cron.trim();
    }
}

export function fromCron(cron: string): SchedulePick {
    const parts = cron.trim().split(/\s+/);
    const custom: SchedulePick = { ...defaultPick, frequency: 'custom', cron };

    if (parts.length !== 5 || parts[3] !== '*' || !/^\d+$/.test(parts[0])) {
        return custom;
    }

    const [minute, hour, day, , weekday] = parts;

    if (hour === '*' && day === '*' && weekday === '*') {
        return { ...defaultPick, frequency: 'hourly', minute: Number(minute), cron };
    }

    if (!/^\d+$/.test(hour)) {
        return custom;
    }

    const time = `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}`;

    if (day === '*' && weekday === '*') {
        return { ...defaultPick, frequency: 'daily', time, cron };
    }

    if (day === '*' && weekday === '1-5') {
        return { ...defaultPick, frequency: 'weekdays', time, cron };
    }

    if (day === '*' && /^[0-7](,[0-7])*$/.test(weekday)) {
        return { ...defaultPick, frequency: 'weekly', time, days: [...new Set(weekday.split(',').map((d) => Number(d) % 7))], cron };
    }

    if (weekday === '*' && /^\d+$/.test(day)) {
        return { ...defaultPick, frequency: 'monthly', time, day: Number(day), cron };
    }

    return custom;
}

/** The schedule in words, like the server's ScheduleLabel, for the preview while it is picked. */
export function describe(pick: SchedulePick): string {
    const [h, m] = hm(pick.time);
    const at = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;

    switch (pick.frequency) {
        case 'daily':
            return `Todos os dias às ${at}`;
        case 'weekdays':
            return `Dias úteis às ${at}`;
        case 'weekly': {
            const names = weekdays.filter((d) => pick.days.includes(d.value)).map((d) => d.plural);

            if (names.length === 0) {
                return 'Escolha pelo menos um dia';
            }

            const last = names.pop();

            return `Às ${names.length ? `${names.join(', ')} e ${last}` : last}, às ${at}`;
        }
        case 'monthly':
            return `Dia ${pick.day} de cada mês, às ${at}`;
        case 'hourly':
            return pick.minute === 0 ? 'De hora a hora' : `De hora a hora, aos ${pick.minute} minutos`;
        default:
            return pick.cron.trim() ? `Personalizado: ${pick.cron.trim()}` : 'Escreva o horário em cron';
    }
}
