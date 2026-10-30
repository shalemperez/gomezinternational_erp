import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ClientPicker } from '@/components/erp/client-picker';
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
import { useCan } from '@/lib/can';
import { formatDate, formatMoney } from '@/lib/format';
import { create, exportMethod, index, show } from '@/routes/sales/receipts';
import type { PickedClient, Receipt } from '@/types/sales';

type Props = {
    rows: Paginated<Receipt>;
    filters: Record<string, string | undefined>;
    statuses: { value: string; label: string }[];
    paymentMethods: { value: string; label: string }[];
};

export default function ReceiptIndex({
    rows,
    filters,
    statuses,
    paymentMethods,
}: Props) {
    const can = useCan();
    const [clientFilter, setClientFilter] = useState<PickedClient | null>(null);

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

    const columns: Column<Receipt>[] = [
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
            key: 'receipt_date',
            label: 'Fecha',
            sortable: true,
            render: (row) => formatDate(row.receipt_date),
        },
        {
            key: 'client',
            label: 'Cliente',
            render: (row) => row.client?.business_name ?? '',
        },
        {
            key: 'payment_method',
            label: 'Método',
            render: (row) => row.payment_method_label,
        },
        {
            key: 'amount_received',
            label: 'Monto',
            sortable: true,
            className: 'text-right',
            render: (row) =>
                formatMoney(
                    row.amount_received,
                    (row.currency?.code as 'DOP' | 'USD') ?? 'DOP',
                ),
        },
        {
            key: 'applied_total',
            label: 'Aplicado',
            sortable: true,
            className: 'text-right',
            render: (row) =>
                formatMoney(
                    row.applied_total,
                    (row.currency?.code as 'DOP' | 'USD') ?? 'DOP',
                ),
        },
        {
            key: 'unapplied_amount',
            label: 'Anticipo',
            sortable: true,
            className: 'text-right',
            render: (row) =>
                formatMoney(
                    row.unapplied_amount,
                    (row.currency?.code as 'DOP' | 'USD') ?? 'DOP',
                ),
        },
        {
            key: 'status',
            label: 'Estado',
            render: (row) => (
                <StatusBadge status={row.status} label={row.status_label} />
            ),
        },
    ];

    return (
        <>
            <Head title="Cobros" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Cobros"
                    actions={
                        <>
                            {can('sales.receipts.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('sales.receipts.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo cobro
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
                    searchPlaceholder="Buscar por número o referencia…"
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
                            <Select
                                value={filters.payment_method ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('payment_method', value)
                                }
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los métodos
                                    </SelectItem>
                                    {paymentMethods.map((method) => (
                                        <SelectItem
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <ClientPicker
                                value={clientFilter}
                                onPick={(client) => {
                                    setClientFilter(client);
                                    applyFilter('client_id', String(client.id));
                                }}
                            />
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

ReceiptIndex.layout = {
    breadcrumbs: [{ title: 'Cobros', href: index() }],
};
