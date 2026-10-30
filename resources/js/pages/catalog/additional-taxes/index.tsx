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
import { formatNumber } from '@/lib/format';
import { useCan } from '@/lib/can';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/catalog/additional-taxes';
import type { AdditionalTax } from '@/types/catalog';

type Props = {
    rows: Paginated<AdditionalTax>;
    filters: Record<string, string | undefined>;
};

export default function AdditionalTaxIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<AdditionalTax>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'calculation_type_label',
            label: 'Tipo de cálculo',
            render: (row) => row.calculation_type_label ?? '',
        },
        {
            key: 'rate',
            label: 'Tasa / Monto',
            render: (row) =>
                row.rate !== null
                    ? `${formatNumber(row.rate, 4)} %`
                    : formatNumber(row.amount, 4),
        },
        {
            key: 'dgii_code',
            label: 'Código DGII',
            render: (row) => row.dgii_code ?? '—',
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Impuestos adicionales" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Impuestos adicionales"
                    actions={
                        <>
                            {can('catalog.additional-taxes.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.additional-taxes.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo impuesto
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
                            {can('catalog.additional-taxes.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.additional-taxes.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar impuesto adicional"
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

AdditionalTaxIndex.layout = {
    breadcrumbs: [{ title: 'Impuestos adicionales', href: index() }],
};
