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
    items,
} from '@/routes/catalog/price-lists';
import type { PriceList } from '@/types/catalog';

type Props = {
    rows: Paginated<PriceList>;
    filters: Record<string, string | undefined>;
};

export default function PriceListIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<PriceList>[] = [
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'currency',
            label: 'Moneda',
            render: (row) => row.currency?.code ?? '—',
        },
        {
            key: 'is_default',
            label: 'Predeterminada',
            render: (row) => (row.is_default ? 'Sí' : '—'),
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Listas de precios" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Listas de precios"
                    actions={
                        <>
                            {can('catalog.price-lists.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.price-lists.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva lista
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
                            {can('catalog.price-lists.view') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={items(row.id)}>Precios</Link>
                                </Button>
                            )}
                            {can('catalog.price-lists.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.price-lists.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar lista de precios"
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

PriceListIndex.layout = {
    breadcrumbs: [{ title: 'Listas de precios', href: index() }],
};
