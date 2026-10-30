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
} from '@/routes/catalog/brands';
import type { Brand } from '@/types/catalog';

type Props = {
    rows: Paginated<Brand>;
    filters: Record<string, string | undefined>;
};

export default function BrandIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<Brand>[] = [
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Marcas" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Marcas"
                    actions={
                        <>
                            {can('catalog.brands.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.brands.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva marca
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
                            {can('catalog.brands.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.brands.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar marca"
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

BrandIndex.layout = { breadcrumbs: [{ title: 'Marcas', href: index() }] };
