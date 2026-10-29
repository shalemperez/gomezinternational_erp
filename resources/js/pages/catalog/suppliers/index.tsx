import { Head, Link, router } from '@inertiajs/react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/catalog/suppliers';
import type { Supplier } from '@/types/catalog';

type Props = {
    rows: Paginated<Supplier>;
    filters: Record<string, string | undefined>;
    countries: { value: string; label: string }[];
};

export default function SupplierIndex({ rows, filters, countries }: Props) {
    const can = useCan();
    const columns: Column<Supplier>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        { key: 'country_label', label: 'País' },
        {
            key: 'tax_id',
            label: 'RNC/ID fiscal',
            render: (row) => row.tax_id ?? row.foreign_tax_id ?? '—',
        },
        {
            key: 'currency',
            label: 'Moneda',
            render: (row) => row.currency?.code ?? '—',
        },
        {
            key: 'is_active',
            label: 'Estado',
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    const applyFilter = (name: string, value: string) => {
        router.get(
            index.url(),
            {
                ...filters,
                [name]: value === 'all' ? undefined : value,
                page: undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Proveedores" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Proveedores"
                    actions={
                        <>
                            {can('catalog.suppliers.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.suppliers.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo proveedor
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
                    toolbar={
                        <>
                            <Select
                                value={filters.country_code ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('country_code', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los países
                                    </SelectItem>
                                    {countries.map((country) => (
                                        <SelectItem
                                            key={country.value}
                                            value={country.value}
                                        >
                                            {country.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('status', value)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los estados
                                    </SelectItem>
                                    <SelectItem value="active">
                                        Activos
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Inactivos
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </>
                    }
                    rowActions={(row) => (
                        <div className="flex justify-end gap-2">
                            {can('catalog.suppliers.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.suppliers.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar proveedor"
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

SupplierIndex.layout = {
    breadcrumbs: [{ title: 'Proveedores', href: index() }],
};
