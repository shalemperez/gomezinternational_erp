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
} from '@/routes/catalog/categories';
import type { ProductCategory } from '@/types/catalog';

type Props = {
    rows: Paginated<ProductCategory>;
    filters: Record<string, string | undefined>;
};

export default function ProductCategoryIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<ProductCategory>[] = [
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'parent',
            label: 'Categoría padre',
            render: (row) => row.parent?.name ?? '—',
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Categorías" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Categorías"
                    actions={
                        <>
                            {can('catalog.categories.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.categories.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva categoría
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
                            {can('catalog.categories.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.categories.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar categoría"
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

ProductCategoryIndex.layout = {
    breadcrumbs: [{ title: 'Categorías', href: index() }],
};
