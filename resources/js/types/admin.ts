import type { User } from '@/types/auth';

export type RoleOption = { name: string; label: string };
export type AdminUser = Pick<
    User,
    'id' | 'name' | 'email' | 'avatar_url' | 'is_active' | 'last_login_at'
> & { roles: string[] };
export type AdminUserForm = Pick<
    User,
    'id' | 'name' | 'email' | 'is_active'
> & { roles: string[] };
export type RoleWithPermissions = {
    name: string;
    label: string;
    permissions: string[];
};
export type PermissionOption = { name: string; label: string; module: string };
export type SettingsValues = Record<string, string | null>;
export type ActivityRow = {
    id: number;
    created_at: string;
    causer: { id: number; name: string } | null;
    impersonator: { id: number; name: string } | null;
    ip: string | null;
    request_id: string | null;
    log_name: string | null;
    event: string | null;
    description: string;
    subject: string | null;
    changes: { field: string; old: unknown; new: unknown }[];
    properties: Record<string, unknown>;
};
