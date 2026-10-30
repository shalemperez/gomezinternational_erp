import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ClientPicker } from '@/components/erp/client-picker';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
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
import { exportMethod, index, show } from '@/routes/sales/debit-notes';
import type { DebitNote, PickedClient } from '@/types/sales';

type Props = {
    rows: Paginated<DebitNote>;
    filters: Record<string, string | undefined>;
    reasons: { value: string; label: string }[];
};

export default function DebitNoteIndex({ rows, filters, reasons }: Props) {
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

    const columns: Column<DebitNote>[] = [
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
            key: 'ncf',
            label: 'NCF',
            sortable: true,
            render: (row) => <span className="font-mono">{row.ncf}</span>,
        },
        {
            key: 'invoice',
            label: 'Factura',
            render: (row) => row.invoice?.number ?? '',
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
            key: 'reason',
            label: 'Motivo',
            render: (row) => row.reason_label,
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
    ];

    return (
        <>
            <Head title="Notas de débito" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Notas de débito"
                    actions={
                        can('sales.debit-notes.export') && (
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
                    searchPlaceholder="Buscar por número, NCF o factura…"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.reason ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('reason', value)
                                }
                            >
                                <SelectTrigger className="w-64">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los motivos
                                    </SelectItem>
                                    {reasons.map((reason) => (
                                        <SelectItem
                                            key={reason.value}
                                            value={reason.value}
                                        >
                                            {reason.label}
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

DebitNoteIndex.layout = {
    breadcrumbs: [{ title: 'Notas de débito', href: index() }],
};
