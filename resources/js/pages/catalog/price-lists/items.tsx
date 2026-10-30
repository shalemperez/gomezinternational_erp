import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/lib/can';
import { formatNumber } from '@/lib/format';
import { index, items } from '@/routes/catalog/price-lists';
import {
    exportMethod as itemsExport,
    update as updateItems,
} from '@/routes/catalog/price-lists/items';
import type { PriceList, PriceListProductRow } from '@/types/catalog';

type Props = {
    list: PriceList;
    products: PriceListProductRow[];
    canEdit: boolean;
};

export default function PriceListItems({ list, products, canEdit }: Props) {
    const can = useCan();
    const [search, setSearch] = useState('');
    const { data, setData, put, processing, transform } = useForm({
        items: products.map((product) => ({
            product_id: product.id,
            price: product.price ?? '',
        })),
    });

    const term = search.trim().toLowerCase();
    const visibleRows = products
        .map((product, position) => ({ product, position }))
        .filter(
            ({ product }) =>
                term === '' ||
                product.sku.toLowerCase().includes(term) ||
                product.name.toLowerCase().includes(term),
        );

    const setPrice = (position: number, price: string) => {
        setData(
            'items',
            data.items.map((item, index) =>
                index === position ? { ...item, price } : item,
            ),
        );
    };

    return (
        <>
            <Head title={`Precios — ${list.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Precios — ${list.name} (${list.currency?.code})`}
                    actions={
                        can('catalog.price-lists.export') && (
                            <ExportMenu
                                xlsxUrl={itemsExport.url(list.id, {
                                    query: { format: 'xlsx' },
                                })}
                                csvUrl={itemsExport.url(list.id, {
                                    query: { format: 'csv' },
                                })}
                            />
                        )
                    }
                />
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        transform((form) => ({
                            items: form.items.filter(
                                (item, position) =>
                                    item.price !==
                                    (products[position].price ?? ''),
                            ),
                        }));
                        put(updateItems.url(list.id));
                    }}
                >
                    <Input
                        placeholder="Buscar por SKU o producto…"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="max-w-sm"
                    />
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Producto</TableHead>
                                    <TableHead>Precio</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visibleRows.map(({ product, position }) => (
                                    <TableRow key={product.id}>
                                        <TableCell className="font-mono">
                                            {product.sku}
                                        </TableCell>
                                        <TableCell>{product.name}</TableCell>
                                        <TableCell>
                                            {canEdit ? (
                                                <Input
                                                    inputMode="decimal"
                                                    aria-label={`Precio de ${product.name}`}
                                                    value={
                                                        data.items[position]
                                                            .price
                                                    }
                                                    onChange={(event) =>
                                                        setPrice(
                                                            position,
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="w-40"
                                                />
                                            ) : (
                                                <span>
                                                    {product.price
                                                        ? formatNumber(
                                                              product.price,
                                                              4,
                                                          )
                                                        : '—'}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="flex gap-2">
                        {canEdit && (
                            <Button type="submit" disabled={processing}>
                                Guardar precios
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={index()}>Volver</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

PriceListItems.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Listas de precios', href: index() },
        { title: props.list.name, href: items.url(props.list.id) },
    ],
});
