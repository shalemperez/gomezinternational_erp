import { Head, Link, router } from '@inertiajs/react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { StatusBadge } from '@/components/erp/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format';
import { useCan } from '@/lib/can';
import { exportMethod, index, show } from '@/routes/inventory/dispatches';
import type { DispatchRow } from '@/types/inventory';

type Props = {
    rows: Paginated<DispatchRow>;
    filters: Record<string, string | undefined>;
    statuses: { value: string; label: string }[];
    warehouses: { id: number; name: string }[];
};

export default function DispatchIndex({
    rows,
    filters,
    statuses,
    warehouses,
}: Props) {
    const can = useCan();

    const applyFilter = (key: string, value: string) =>
        router.get(
            index.url(),
            {
                ...filters,
                [key]: value === 'all' || value === '' ? undefined : value,
                page: undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const columns: Column<DispatchRow>[] = [
        {
            key: 'number',
            label: 'Número',
            sortable: true,
            render: (row) => (
                <Link className="font-mono underline" href={show.url(row.id)}>
                    {row.number ?? 'Borrador'}
                </Link>
            ),
        },
        {
            key: 'dispatch_date',
            label: 'Fecha',
            sortable: true,
            render: (row) => formatDate(row.dispatch_date),
        },
        {
            key: 'invoice',
            label: 'Factura',
            render: (row) => row.invoice?.number ?? '',
        },
        {
            key: 'client',
            label: 'Cliente',
            render: (row) => row.client?.business_name ?? '',
        },
        {
            key: 'warehouse',
            label: 'Almacén',
            render: (row) => row.warehouse?.name ?? '',
        },
        {
            key: 'status',
            label: 'Estado',
            sortable: true,
            render: (row) => (
                <StatusBadge status={row.status} label={row.status_label} />
            ),
        },
    ];

    return (
        <>
            <Head title="Conduces" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Conduces"
                    actions={
                        can('inventory.dispatches.export') && (
                            <ExportMenu
                                xlsxUrl={exportMethod.url({
                                    query: { ...filters, format: 'xlsx' },
                                })}
                                csvUrl={exportMethod.url({
                                    query: { ...filters, format: 'csv' },
                                })}
                            />
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar por número, chofer o vehículo…"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los estados
                                    </SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.warehouse_id ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('warehouse_id', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los almacenes
                                    </SelectItem>
                                    {warehouses.map((warehouse) => (
                                        <SelectItem
                                            key={warehouse.id}
                                            value={String(warehouse.id)}
                                        >
                                            {warehouse.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                className="w-40"
                                aria-label="Desde"
                                value={filters.from ?? ''}
                                onChange={(event) =>
                                    applyFilter('from', event.target.value)
                                }
                            />
                            <Input
                                type="date"
                                className="w-40"
                                aria-label="Hasta"
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    applyFilter('to', event.target.value)
                                }
                            />
                        </div>
                    }
                    rowActions={(row) => (
                        <div className="flex justify-end">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={show.url(row.id)}>Ver</Link>
                            </Button>
                        </div>
                    )}
                />
            </div>
        </>
    );
}

DispatchIndex.layout = {
    breadcrumbs: [{ title: 'Conduces', href: index() }],
};
