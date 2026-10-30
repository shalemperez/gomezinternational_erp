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
} from '@/routes/catalog/products';
import type { Product } from '@/types/catalog';

type Props = {
    rows: Paginated<Product>;
    filters: Record<string, string | undefined>;
    brands: { id: number; name: string }[];
    categories: { id: number; name: string }[];
};

export default function ProductIndex({
    rows,
    filters,
    brands,
    categories,
}: Props) {
    const can = useCan();
    const columns: Column<Product>[] = [
        { key: 'sku', label: 'SKU', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'brand',
            label: 'Marca',
            render: (row) => row.brand?.name ?? '—',
        },
        {
            key: 'category',
            label: 'Categoría',
            render: (row) => row.category?.name ?? '—',
        },
        {
            key: 'itbis_rate',
            label: 'ITBIS',
            render: (row) =>
                row.itbis_rate === '0.00'
                    ? 'Exento'
                    : `${Number(row.itbis_rate)} %`,
        },
        {
            key: 'tracks_lots',
            label: 'Lotes',
            render: (row) => (row.tracks_lots ? 'Sí' : 'No'),
        },
        {
            key: 'is_active',
            label: 'Estado',
            sortable: true,
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    const applyFilter = (key: string, value: string) =>
        router.get(
            index.url(),
            {
                ...filters,
                [key]: value === 'all' ? undefined : value,
                page: undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    return (
        <>
            <Head title="Productos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Productos"
                    actions={
                        <>
                            {can('catalog.products.export') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('catalog.products.create') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nuevo producto
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
                                value={filters.brand_id ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('brand_id', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="Todas las marcas" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todas las marcas
                                    </SelectItem>
                                    {brands.map((brand) => (
                                        <SelectItem
                                            key={brand.id}
                                            value={String(brand.id)}
                                        >
                                            {brand.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.category_id ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('category_id', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="Todas las categorías" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todas las categorías
                                    </SelectItem>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            {category.name}
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
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
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
                            {can('catalog.products.update') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('catalog.products.delete') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar producto"
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

ProductIndex.layout = {
    breadcrumbs: [{ title: 'Productos', href: index() }],
};
