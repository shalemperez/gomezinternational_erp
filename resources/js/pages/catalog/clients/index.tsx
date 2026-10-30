import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActiveBadge } from '@/components/erp/active-badge';
import { ConfirmDelete } from '@/components/erp/confirm-delete';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import { formatMoney } from '@/lib/format';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/catalog/clients';
import type { Client } from '@/types/catalog';

type Props = {
    rows: Paginated<Client>;
    filters: Record<string, string | undefined>;
    salespeople: { id: number; name: string }[];
};

export default function ClientIndex({ rows, filters, salespeople }: Props) {
    const can = useCan();
    const columns: Column<Client>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'business_name', label: 'Razón social', sortable: true },
        {
            key: 'trade_name',
            label: 'Nombre comercial',
            render: (row) => row.trade_name ?? '—',
        },
        {
            key: 'tax_id',
            label: 'RNC/Cédula',
            render: (row) => row.formatted_tax_id ?? '—',
        },
        {
            key: 'salesperson',
            label: 'Vendedor',
            render: (row) => row.salesperson?.name ?? '—',
        },
        { key: 'credit_days', label: 'Días crédito' },
        {
            key: 'credit_limit',
            label: 'Límite',
            render: (row) =>
                formatMoney(
                    row.credit_limit,
                    row.currency?.code === 'USD' ? 'USD' : 'DOP',
                ),
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    const applyFilter = (name: string, value: string) => {
        router.get(
            index.url(),
            {
                ...filters,
                [name]: value === 'all' ? undefined : value,
                page: undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Clientes" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Clientes"
                    actions={
                        <>
                            {can('catalog.clients.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.clients.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo cliente
                                    </Link>
                                </Button>
                            )}
                        </>
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
                                value={filters.salesperson_id ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('salesperson_id', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los vendedores
                                    </SelectItem>
                                    {salespeople.map((salesperson) => (
                                        <SelectItem
                                            key={salesperson.id}
                                            value={String(salesperson.id)}
                                        >
                                            {salesperson.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los estados
                                    </SelectItem>
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
                            {can('catalog.clients.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.clients.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar cliente"
                                    description="Esta acción no se puede deshacer."
                                />
                            )}
                        </div>
                    )}
                />
            </div>
        </>
    );
}

ClientIndex.layout = {
    breadcrumbs: [{ title: 'Clientes', href: index() }],
};
