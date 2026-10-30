import { Head, router } from '@inertiajs/react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { ProductPicker } from '@/components/erp/product-picker';
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
import { formatDateTime, formatNumber } from '@/lib/format';
import { exportMethod, index } from '@/routes/inventory/movements';
import type { StockMovementRow } from '@/types/inventory';

type Props = {
    rows: Paginated<StockMovementRow>;
    filters: Record<string, string | undefined>;
    product: { id: number; sku: string; name: string } | null;
    warehouses: { id: number; name: string }[];
    types: { value: string; label: string }[];
    canViewCosts: boolean;
};

export default function StockMovementIndex({
    rows,
    filters,
    product,
    warehouses,
    types,
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

    const columns: Column<StockMovementRow>[] = [
        {
            key: 'moved_at',
            label: 'Fecha/hora',
            sortable: true,
            render: (row) => formatDateTime(row.moved_at),
        },
        {
            key: 'type',
            label: 'Tipo',
            render: (row) => row.type_label,
        },
        {
            key: 'sku',
            label: 'Producto',
            render: (row) => [row.sku, row.name].filter(Boolean).join(' '),
        },
        {
            key: 'warehouse',
            label: 'Almacén',
            render: (row) => row.warehouse ?? '',
        },
        {
            key: 'lot_code',
            label: 'Lote',
            render: (row) => row.lot_code ?? '',
        },
        {
            key: 'signed_quantity',
            label: 'Cantidad',
            sortable: true,
            className: 'text-right',
            render: (row) => (
                <span
                    className={
                        row.signed_quantity.startsWith('-')
                            ? 'text-red-600'
                            : undefined
                    }
                >
                    {formatNumber(row.signed_quantity, 4)}
                </span>
            ),
        },
        {
            key: 'balance_after',
            label: 'Saldo',
            sortable: true,
            className: 'text-right',
            render: (row) => formatNumber(row.balance_after, 4),
        },
        {
            key: 'reference_label',
            label: 'Referencia',
            render: (row) => row.reference_label,
        },
        {
            key: 'user_name',
            label: 'Usuario',
            render: (row) => row.user_name ?? '',
        },
        {
            key: 'notes',
            label: 'Notas',
            render: (row) => row.notes ?? '',
        },
        ...(canViewCosts
            ? [
                  {
                      key: 'unit_cost',
                      label: 'Costo unit.',
                      className: 'text-right',
                      render: (row: StockMovementRow) =>
                          formatNumber(row.unit_cost, 4),
                  },
                  {
                      key: 'total_cost',
                      label: 'Costo total',
                      className: 'text-right',
                      render: (row: StockMovementRow) =>
                          formatNumber(row.total_cost, 2),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Movimientos de inventario (kardex)" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Movimientos de inventario (kardex)"
                    actions={
                        can('inventory.movements.export') && (
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
                    searchPlaceholder="Buscar en notas…"
                    emptyText="No hay movimientos registrados"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <ProductPicker
                                clientId={null}
                                onPick={(picked) =>
                                    applyFilter('product_id', String(picked.id))
                                }
                                trigger={
                                    <Button type="button" variant="outline">
                                        {product
                                            ? `${product.sku} ${product.name}`
                                            : 'Todos los productos'}
                                    </Button>
                                }
                            />
                            {product && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        applyFilter('product_id', '')
                                    }
                                >
                                    Quitar producto
                                </Button>
                            )}
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
                            <Select
                                value={filters.type ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('type', value)
                                }
                            >
                                <SelectTrigger className="w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los tipos
                                    </SelectItem>
                                    {types.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
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

StockMovementIndex.layout = {
    breadcrumbs: [{ title: 'Movimientos de inventario', href: index() }],
};
