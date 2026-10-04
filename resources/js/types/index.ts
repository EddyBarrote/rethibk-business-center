export type Role = 'owner' | 'admin' | 'manager' | 'member';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label: string;
    can_manage_tenant: boolean;
}

export interface SharedProps {
    [key: string]: unknown;
    app: { name: string; locale: string };
    tenant: { name: string; slug: string } | null;
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
}

export interface Option {
    value: string;
    label: string;
}
