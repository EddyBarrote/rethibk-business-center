/*
 * Agents write console paths ("/approvals", "/tasks/5") in briefings and
 * replies. People read the name of the page instead of the path, and paths to
 * areas the console no longer has stay as plain text.
 */

const pages: Record<string, [list: string, one: string]> = {
    agents: ['Agentes', 'Agente'],
    approvals: ['Aprovações', 'Aprovação'],
    briefings: ['Briefings', 'Briefing'],
    capabilities: ['Capacidades', 'Capacidade'],
    departments: ['Departamentos', 'Departamento'],
    documents: ['Documentos', 'Documento'],
    erp: ['ERP', 'ERP'],
    goals: ['Objectivos', 'Objectivo'],
    inbox: ['Email', 'Email'],
    knowledge: ['Conhecimento', 'Conhecimento'],
    notifications: ['Notificações', 'Notificação'],
    org: ['Organigrama', 'Organigrama'],
    painel: ['Painel', 'Painel'],
    projects: ['Projectos', 'Projecto'],
    reports: ['Relatórios', 'Relatório'],
    roles: ['Papéis', 'Papel'],
    routines: ['Rotinas', 'Rotina'],
    runs: ['Execuções', 'Execução'],
    settings: ['Definições', 'Definições'],
    skills: ['Skills', 'Skill'],
    tasks: ['Tarefas', 'Tarefa'],
    users: ['Utilizadores', 'Utilizador'],
    workflows: ['Fluxos de trabalho', 'Fluxo'],
};

/** Areas the console had before the ERP took them over; old briefings still point at them. */
const removed: Record<string, [list: string, one: string]> = {
    clients: ['Clientes', 'Cliente'],
    contracts: ['Contratos', 'Contrato'],
    finance: ['Finanças', 'Finanças'],
    procurement: ['Compras', 'Compra'],
    purchases: ['Compras', 'Compra'],
    tenders: ['Concursos', 'Concurso'],
};

/**
 * "/approvals" → Aprovações, "/settings/runs/12" → Execução (never the number: things are
 * named the human way). `live` is false for an
 * area the console no longer has; null when the path is not a page at all.
 */
export function pathLabel(href: string): { label: string; live: boolean } | null {
    if (href === '/') {
        return { label: 'A minha caixa', live: true };
    }
    const match = href.match(/^(?:\/settings)?\/([a-z-]+)(?:\/(\d+))?\/?(?:[?#].*)?$/);
    const page = match ? (pages[match[1]] ?? removed[match[1]]) : undefined;
    if (!match || !page) {
        return null;
    }

    return { label: match[2] ? page[1] : page[0], live: match[1] in pages };
}

/** A path inside the console (not an external URL, not a protocol link). */
export const isConsolePath = (href: string | undefined): href is string => !!href && href.startsWith('/') && !href.startsWith('//');
