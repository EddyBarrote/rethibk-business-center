import { Briefcase, Building2, CalendarDays, Folder, HardHat, type LucideIcon, Megaphone, Ticket, Truck } from 'lucide-react';

/*
 * A project's colour and icon as a channel (docs/DECISOES.md, "Canais por
 * projecto"); its tasks wear the colour. The keys are what the server keeps.
 */

export const projectColors: Record<string, string> = {
    blue: '#2563eb',
    green: '#16a34a',
    amber: '#d97706',
    red: '#dc2626',
    purple: '#7c3aed',
    pink: '#db2777',
    teal: '#0d9488',
    slate: '#64748b',
};

export const projectIcons: Record<string, LucideIcon> = {
    folder: Folder,
    building: Building2,
    'hard-hat': HardHat,
    truck: Truck,
    calendar: CalendarDays,
    briefcase: Briefcase,
    ticket: Ticket,
    megaphone: Megaphone,
};

export const colorOf = (key: string | null | undefined) => (key ? projectColors[key] : undefined);
