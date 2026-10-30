import { Head, Link, router } from '@inertiajs/react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { exportMethod, index, show } from '@/routes/fiscal/received-ecfs';
import type { ReceivedEcfRow } from '@/types/ecf';

type Props = {
    rows: Paginated<ReceivedEcfRow>;
    filters: Record<string, string | undefined>;
    commercialStatuses: { value: string; label: string }[];
};

const commercialVariants: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'outline',
    approved: 'default',
    rejected: 'destructive',
};

export default function ReceivedEcfIndex({
    rows,
    filters,
    commercialStatuses,
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

    const columns: Column<ReceivedEcfRow>[] = [
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
            key: 'rnc_emisor',
            label: 'RNC emisor',
            sortable: true,
            render: (row) => (
                <span className="font-mono">{row.rnc_emisor}</span>
            ),
        },
        {
            key: 'razon_social_emisor',
            label: 'Proveedor',
            render: (row) => row.razon_social_emisor ?? '',
        },
        {
            key: 'fecha_emision',
            label: 'Fecha emisión',
            sortable: true,
            render: (row) => formatDate(row.fecha_emision),
        },
        {
            key: 'monto_total',
            label: 'Monto total',
            sortable: true,
            className: 'text-right',
            render: (row) => formatMoney(row.monto_total),
        },
        {
            key: 'status',
            label: 'Recepción',
            render: (row) => row.status_label,
        },
        {
            key: 'commercial_status',
            label: 'Aprobación comercial',
            render: (row) => (
                <Badge
                    variant={
                        commercialVariants[row.commercial_status] ?? 'outline'
                    }
                >
                    {row.commercial_status_label}
                </Badge>
            ),
        },
        {
            key: 'received_at',
            label: 'Fecha recepción',
            sortable: true,
            render: (row) => formatDateTime(row.received_at),
        },
    ];

    return (
        <>
            <Head title="e-CF recibidos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="e-CF recibidos"
                    actions={
                        can('fiscal.received-ecf.export') && (
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
                    searchPlaceholder="Buscar por RNC, proveedor o e-NCF…"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={filters.commercial_status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('commercial_status', value)
                                }
                            >
                                <SelectTrigger className="w-52">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todas las aprobaciones
                                    </SelectItem>
                                    {commercialStatuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
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
