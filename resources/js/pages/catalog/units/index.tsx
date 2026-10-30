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
} from '@/routes/catalog/units';
import type { UnitOfMeasure } from '@/types/catalog';

type Props = {
    rows: Paginated<UnitOfMeasure>;
    filters: Record<string, string | undefined>;
};

export default function UnitOfMeasureIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<UnitOfMeasure>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Unidades de medida" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Unidades de medida"
                    actions={
                        <>
                            {can('catalog.units.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.units.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva unidad
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
                            {can('catalog.units.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.units.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar unidad"
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

UnitOfMeasureIndex.layout = {
    breadcrumbs: [{ title: 'Unidades de medida', href: index() }],
};
