import { Head, router } from '@inertiajs/react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { PageHeader } from '@/components/erp/page-header';
import { StatusBadge } from '@/components/erp/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate, formatNumber } from '@/lib/format';
import { index } from '@/routes/inventory/lots';
import type { LotRow, LotState } from '@/types/inventory';

type Props = {
    rows: Paginated<LotRow>;
    filters: Record<string, string | undefined>;
    warehouses: { id: number; name: string }[];
    expiringDays: number;
};

const stateLabels: Record<LotState, string> = {
    expired: 'Vencido',
    expiring: 'Por vencer',
    ok: 'Vigente',
};

export default function LotIndex({
    rows,
    filters,
    warehouses,
    expiringDays,
}: Props) {
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

    const columns: Column<LotRow>[] = [
        {
            key: 'sku',
            label: 'SKU',
            sortable: true,
            render: (row) => row.sku,
        },
        {
            key: 'name',
            label: 'Producto',
            sortable: true,
            render: (row) => row.name,
        },
        {
            key: 'lot_code',
            label: 'Lote',
            sortable: true,
            render: (row) => row.lot_code,
        },
        {
            key: 'expires_on',
            label: 'Vence',
            sortable: true,
            render: (row) => formatDate(row.expires_on),
        },
        {
            key: 'days_to_expiry',
            label: 'Días',
            className: 'text-right',
            render: (row) => row.days_to_expiry ?? '',
        },
        {
            key: 'warehouse',
            label: 'Almacén',
            render: (row) => row.warehouse,
        },
        {
            key: 'quantity',
            label: 'Existencia',
            sortable: true,
            className: 'text-right',
            render: (row) => formatNumber(row.quantity, 4),
        },
        {
            key: 'state',
            label: 'Estado',
            render: (row) => (
                <StatusBadge
                    status={row.state}
                    label={stateLabels[row.state]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Lotes y vencimientos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Lotes y vencimientos" />
                <Alert>
                    <AlertDescription>
                        Se consideran por vencer los lotes que vencen en los
                        próximos {expiringDays} días
                    </AlertDescription>
                </Alert>
                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar por SKU, producto o lote…"
                    emptyText="No hay lotes con existencia"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
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
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="expiring-only"
                                    checked={filters.expiring_only === '1'}
                                    onCheckedChange={(checked) =>
                                        applyFilter(
                                            'expiring_only',
                                            checked === true ? '1' : '',
                                        )
                                    }
                                />
                                <Label htmlFor="expiring-only">
                                    Solo por vencer
                                </Label>
                            </div>
                        </div>
                    }
                />
            </div>
        </>
    );
}

LotIndex.layout = {
    breadcrumbs: [{ title: 'Lotes y vencimientos', href: index() }],
};
