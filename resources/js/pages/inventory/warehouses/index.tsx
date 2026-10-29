import { Head, Link } from '@inertiajs/react';
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
import { useCan } from '@/lib/can';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/inventory/warehouses';
import type { Warehouse } from '@/types/inventory';

type Props = {
    rows: Paginated<Warehouse>;
    filters: Record<string, string | undefined>;
};

export default function WarehouseIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<Warehouse>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        { key: 'address', label: 'Dirección' },
        {
            key: 'is_default',
            label: 'Predeterminado',
            sortable: true,
            render: (row) => (row.is_default ? 'Sí' : '—'),
        },
        {
            key: 'is_active',
            label: 'Estado',
            sortable: true,
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Almacenes" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Almacenes"
                    actions={
                        <>
                            {can('inventory.warehouses.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('inventory.warehouses.manage') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo almacén
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
                    rowActions={(row) => (
                        <div className="flex justify-end gap-2">
                            {can('inventory.warehouses.manage') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('inventory.warehouses.manage') &&
                                !row.is_default && (
                                    <ConfirmDelete
                                        url={destroy.url(row.id)}
                                        title="Eliminar almacén"
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

WarehouseIndex.layout = {
    breadcrumbs: [{ title: 'Almacenes', href: index() }],
};
