import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ExportMenu } from '@/components/erp/export-menu';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
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
import { formatDate, formatMoney } from '@/lib/format';
import { useCan } from '@/lib/can';
import { create, exportMethod, index, show } from '@/routes/sales/proformas';
import type { Proforma } from '@/types/sales';

type Props = {
    rows: Paginated<Proforma>;
    filters: Record<string, string | undefined>;
    statuses: { value: string; label: string }[];
    salespeople: { id: number; name: string }[];
    canViewAll: boolean;
};

export default function ProformaIndex({
    rows,
    filters,
    statuses,
    salespeople,
    canViewAll,
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

    const columns: Column<Proforma>[] = [
        {
            key: 'number',
            label: 'Número',
            sortable: true,
            render: (row) => (
                <Link className="font-mono underline" href={show.url(row.id)}>
                    {row.number}
                </Link>
            ),
        },
        {
            key: 'issue_date',
            label: 'Fecha',
            sortable: true,
            render: (row) => formatDate(row.issue_date),
        },
        {
            key: 'client',
            label: 'Cliente',
            render: (row) => row.client?.business_name ?? '',
        },
        {
            key: 'salesperson',
            label: 'Vendedor',
            render: (row) => row.salesperson?.name ?? '',
        },
        {
            key: 'valid_until',
            label: 'Válida hasta',
            sortable: true,
            render: (row) => formatDate(row.valid_until),
        },
        {
            key: 'total',
            label: 'Total',
            sortable: true,
            className: 'text-right',
            render: (row) =>
                formatMoney(
                    row.total,
                    (row.currency?.code as 'DOP' | 'USD') ?? 'DOP',
                ),
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
            <Head title="Proformas" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Proformas"
                    actions={
                        <>
                            {can('sales.proformas.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('sales.proformas.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva proforma
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
                    searchPlaceholder="Buscar por número…"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-44">
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
                            {canViewAll && (
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
                                        {salespeople.map((person) => (
                                            <SelectItem
                                                key={person.id}
                                                value={String(person.id)}
                                            >
                                                {person.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
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

ProformaIndex.layout = {
    breadcrumbs: [{ title: 'Proformas', href: index() }],
};
