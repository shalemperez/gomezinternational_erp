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
