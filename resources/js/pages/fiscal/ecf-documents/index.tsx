import { Head, Link } from '@inertiajs/react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { EcfStatusBadge } from '@/components/erp/ecf-status-card';
import { PageHeader } from '@/components/erp/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { useCan } from '@/lib/can';
import { formatDateTime, formatMoney } from '@/lib/format';
import { index, show, exportMethod } from '@/routes/fiscal/ecf-documents';
import type { EcfDocumentRow } from '@/types/ecf';

type Props = {
    rows: Paginated<EcfDocumentRow>;
    filters: Record<string, string | undefined>;
    statuses: { value: string; label: string }[];
};

const flows = [
    { value: 'ecf', label: 'e-CF' },
    { value: 'rfce', label: 'Resumen (RFCE)' },
];

export default function EcfDocumentIndex({ rows, filters, statuses }: Props) {
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

    const columns: Column<EcfDocumentRow>[] = [
        {
            key: 'encf',
            label: 'e-NCF',
            sortable: true,
            render: (row) => (
                <Link className="font-mono underline" href={show.url(row.id)}>
                    {row.encf}
                </Link>
            ),
        },
        {
            key: 'type',
            label: 'Tipo',
            render: (row) => row.type_label,
        },
        {
            key: 'document',
            label: 'Documento',
            render: (row) => (
                <Link className="underline" href={row.document.url}>
                    {row.document.number}
                </Link>
            ),
        },
        {
            key: 'client',
            label: 'Cliente',
            render: (row) => row.document.client_name ?? '',
        },
        {
            key: 'total',
            label: 'Total',
            className: 'text-right',
            render: (row) =>
                formatMoney(
                    row.document.total,
                    (row.document.currency as 'DOP' | 'USD') ?? 'DOP',
                ),
        },
        {
            key: 'flow',
            label: 'Flujo',
            render: (row) =>
                flows.find((flow) => flow.value === row.flow)?.label ??
                row.flow,
        },
        {
            key: 'status',
            label: 'Estado',
            render: (row) => (
                <EcfStatusBadge status={row.status} label={row.status_label} />
            ),
        },
        {
            key: 'track_id',
            label: 'TrackId',
            render: (row) => row.track_id ?? '',
        },
        {
            key: 'signed_at',
            label: 'Fecha firma',
            sortable: true,
            render: (row) => formatDateTime(row.signed_at),
        },
        {
            key: 'attempts',
            label: 'Intentos',
            sortable: true,
            className: 'text-right',
            render: (row) => row.attempts,
        },
    ];

    return (
        <>
            <Head title="Comprobantes electrónicos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Comprobantes electrónicos"
                    actions={
                        can('fiscal.ecf.export') && (
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
                    searchPlaceholder="Buscar por e-NCF o TrackId…"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-52">
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
                                value={filters.flow ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('flow', value)
                                }
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los flujos
                                    </SelectItem>
                                    {flows.map((flow) => (
                                        <SelectItem
                                            key={flow.value}
                                            value={flow.value}
                                        >
                                            {flow.label}
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
                />
            </div>
        </>
    );
}
