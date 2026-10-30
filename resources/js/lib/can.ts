import { usePage } from '@inertiajs/react';

export function useCan(): (permission: string) => boolean {
    const { auth } = usePage().props;
    return (permission: string) =>
        auth.roles.includes('super-admin') ||
        auth.permissions.includes(permission);
}
