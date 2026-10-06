import {
    AtSign,
    BookOpen,
    Building2,
    ChartColumn,
    type LucideIcon,
    Palette,
    PlugZap,
    Puzzle,
    ShieldCheck,
    SunMoon,
    UserRound,
    Users,
} from 'lucide-react';

/*
 * Definições: every setting in one place, in five groups (docs/DECISOES.md,
 * "Definições num só lugar"). Each section shows only to people with one of
 * its permissions, the same ones the server checks; a group with nothing to
 * show disappears.
 */

export interface SettingsItem {
    label: string;
    href: string;
    icon: LucideIcon;
    description: string;
    /** Shown only to people with one of these permissions. */
    permissions?: string[];
}

export interface SettingsGroup {
    label: string;
    items: SettingsItem[];
}

export const settingsGroups: SettingsGroup[] = [
    {
        label: 'Empresa',
        items: [
            {
                label: 'Marca',
                href: '/settings/brand',
                icon: Palette,
                description: 'Cor, logótipo e rodapé dos documentos que os agentes geram.',
                permissions: ['company.manage'],
            },
            {
                label: 'Departamentos',
                href: '/settings/departments',
                icon: Building2,
                description: 'Direcções e subdepartamentos, com as pessoas de cada um.',
            },
        ],
    },
    {
        label: 'Pessoas e acessos',
        items: [
            {
                label: 'Utilizadores',
                href: '/settings/users',
                icon: Users,
                description: 'Quem entra na plataforma, com que papel e em que departamento.',
                permissions: ['people.manage'],
            },
            {
                label: 'Papéis e acessos',
                href: '/settings/roles',
                icon: ShieldCheck,
                description: 'O que cada papel pode fazer, área a área.',
                permissions: ['people.manage'],
            },
        ],
    },
    {
        label: 'Comunicação',
        items: [
            {
                label: 'Caixas de email',
                href: '/settings/mailboxes',
                icon: AtSign,
                description: 'As caixas ligadas e os agentes que as lêem.',
                permissions: ['emails.own_mailboxes', 'emails.manage_mailboxes'],
            },
            {
                label: 'Integrações',
                href: '/settings/integrations',
                icon: PlugZap,
                description: 'O ERP e os conectores que ligam os agentes a outros sistemas.',
                permissions: ['company.manage', 'catalog.manage'],
            },
        ],
    },
    {
        label: 'Agentes de IA',
        items: [
            {
                label: 'Capacidades',
                href: '/settings/capabilities',
                icon: Puzzle,
                description: 'O que os agentes conseguem fazer, e o risco de cada acção.',
                permissions: ['catalog.manage'],
            },
            {
                label: 'Skills',
                href: '/settings/skills',
                icon: BookOpen,
                description: 'Instruções da organização para cada tipo de trabalho.',
                permissions: ['catalog.manage'],
            },
            {
                label: 'Consumo de IA',
                href: '/settings/usage',
                icon: ChartColumn,
                description: 'Quanto os agentes gastaram este mês, face ao tecto.',
                permissions: ['costs.view'],
            },
        ],
    },
    {
        label: 'A minha conta',
        items: [
            { label: 'Perfil', href: '/settings/profile', icon: UserRound, description: 'O seu nome e a sua palavra-passe.' },
            { label: 'Aparência', href: '/settings/appearance', icon: SunMoon, description: 'Tema claro, escuro ou o do sistema.' },
        ],
    },
];

/** The groups this person may see, without the sections they may not open. */
export function visibleSettings(permissions: string[] | undefined): SettingsGroup[] {
    const allowed = (item: SettingsItem) => !item.permissions || item.permissions.some((p) => permissions?.includes(p));

    return settingsGroups.map((group) => ({ ...group, items: group.items.filter(allowed) })).filter((group) => group.items.length > 0);
}

/** The section a settings address belongs to: /settings/integrations/erp is Integrações. */
export function settingsSection(url: string): SettingsItem | undefined {
    const path = url.split('?')[0];

    return settingsGroups
        .flatMap((group) => group.items)
        .filter((item) => path === item.href || path.startsWith(`${item.href}/`))
        .sort((a, b) => b.href.length - a.href.length)[0];
}
