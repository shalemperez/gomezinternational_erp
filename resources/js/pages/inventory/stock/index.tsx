import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import { formatNumber } from '@/lib/format';
import { exportMethod, index } from '@/routes/inventory/stock';
import type { StockRow } from '@/types/inventory';

type Props = {
    rows: Paginated<StockRow>;
    filters: Record<string, string | undefined>;
    warehouses: { id: number; name: string }[];
    canViewCosts: boolean;
};

export default function StockIndex({
    rows,
    filters,
    warehouses,
    canViewCosts,
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

    const highlight = (row: StockRow, text: ReactNode) => (
        <span className={row.is_low ? 'text-red-600' : undefined}>{text}</span>
    );

    const columns: Column<StockRow>[] = [
        {
            key: 'sku',
            label: 'SKU',
            sortable: true,
            render: (row) => highlight(row, row.sku),
        },
        {
            key: 'name',
            label: 'Producto',
            sortable: true,
            render: (row) => highlight(row, row.name),
        },
        {
            key: 'warehouse',
            label: 'Almacén',
            render: (row) => highlight(row, row.warehouse),
        },
        {
            key: 'quantity',
            label: 'Existencia',
            sortable: true,
            className: 'text-right',
            render: (row) => highlight(row, formatNumber(row.quantity, 4)),
        },
        {
            key: 'reserved_quantity',
            label: 'Reservado',
            className: 'text-right',
            render: (row) =>
                highlight(row, formatNumber(row.reserved_quantity, 4)),
        },
        {
            key: 'available',
            label: 'Disponible',
            sortable: true,
            className: 'text-right',
            render: (row) => highlight(row, formatNumber(row.available, 4)),
        },
        {
            key: 'minimum_stock',
            label: 'Mínimo',
            className: 'text-right',
            render: (row) => highlight(row, formatNumber(row.minimum_stock, 4)),
        },
        ...(canViewCosts
            ? [
                  {
                      key: 'average_cost',
                      label: 'Costo promedio',
                      className: 'text-right',
                      render: (row: StockRow) =>
                          highlight(row, formatNumber(row.average_cost, 4)),
                  },
                  {
                      key: 'stock_value',
                      label: 'Valor',
                      className: 'text-right',
                      render: (row: StockRow) =>
                          highlight(row, formatNumber(row.stock_value, 2)),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Existencias" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Existencias"
                    actions={
                        can('inventory.stock.export') && (
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
                    searchPlaceholder="Buscar por SKU o producto…"
                    emptyText="No hay existencias registradas"
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
                                    id="low-only"
                                    checked={filters.low_only === '1'}
                                    onCheckedChange={(checked) =>
                                        applyFilter(
                                            'low_only',
                                            checked === true ? '1' : '',
                                        )
                                    }
                                />
                                <Label htmlFor="low-only">
                                    Solo bajo mínimo
                                </Label>
                            </div>
                        </div>
                    }
                />
            </div>
        </>
    );
}

StockIndex.layout = {
    breadcrumbs: [{ title: 'Existencias', href: index() }],
};
