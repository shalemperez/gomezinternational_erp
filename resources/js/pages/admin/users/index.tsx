import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActiveBadge } from '@/components/erp/active-badge';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import { formatDateTime } from '@/lib/format';
import { create, edit, impersonate, index } from '@/routes/admin/users';
import type { AdminUser, RoleOption } from '@/types/admin';

type Props = {
    rows: Paginated<AdminUser>;
    filters: Record<string, string | undefined>;
    roleOptions: RoleOption[];
    canImpersonate: boolean;
    authId: number;
};

export default function AdminUsersIndex({
    rows,
    filters,
    roleOptions,
    canImpersonate,
    authId,
}: Props) {
    const can = useCan();
    const labelOf = (name: string) =>
        roleOptions.find((option) => option.name === name)?.label ?? name;
    const changeFilter = (key: 'role' | 'status', value: string) => {
        router.get(
            index.url(),
            {
                ...filters,
                [key]: value === 'all' ? undefined : value,
                page: undefined,
            },
            { preserveState: true, replace: true },
        );
    };
    const columns: Column<AdminUser>[] = [
        { key: 'name', label: 'Nombre', sortable: true },
        { key: 'email', label: 'Correo', sortable: true },
        {
            key: 'roles',
            label: 'Roles',
            render: (row) => (
                <div className="flex flex-wrap gap-1">
                    {row.roles.map((role) => (
                        <Badge key={role} variant="outline">
                            {labelOf(role)}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
        {
            key: 'last_login_at',
            label: 'Último acceso',
            sortable: true,
            render: (row) => formatDateTime(row.last_login_at),
        },
    ];

    return (
        <>
            <Head title="Usuarios" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Usuarios"
                    actions={
                        can('admin.users.create') && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus /> Invitar usuario
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar…"
                    toolbar={
                        <>
                            <Select
                                value={filters.role ?? 'all'}
                                onValueChange={(value) =>
                                    changeFilter('role', value)
                                }
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue placeholder="Todos los roles" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los roles
                                    </SelectItem>
                                    {roleOptions.map((option) => (
                                        <SelectItem
                                            key={option.name}
                                            value={option.name}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    changeFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    <SelectItem value="active">
                                        Activos
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Inactivos
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </>
                    }
                    rowActions={(row) => (
                        <div className="flex justify-end gap-2">
                            {can('admin.users.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {canImpersonate &&
                                row.id !== authId &&
                                row.is_active && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(impersonate.url(row.id))
                                        }
                                    >
                                        Entrar como
                                    </Button>
                                )}
                        </div>
                    )}
                />
            </div>
        </>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [{ title: 'Usuarios', href: index() }],
};
