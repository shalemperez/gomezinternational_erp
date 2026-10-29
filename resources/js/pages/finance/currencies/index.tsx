import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActiveBadge } from '@/components/erp/active-badge';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/finance/currencies';
import type { Currency } from '@/types/finance';

type Props = {
    rows: Paginated<Currency>;
    filters: Record<string, string | undefined>;
};

export default function CurrenciesIndex({ rows, filters }: Props) {
    const columns: Column<Currency>[] = [
        { key: 'code', label: 'Código', sortable: true },
        { key: 'name', label: 'Nombre', sortable: true },
        { key: 'symbol', label: 'Símbolo' },
        { key: 'decimal_places', label: 'Decimales' },
        {
            key: 'is_active',
            label: 'Estado',
            sortable: true,
            render: (row) => <ActiveBadge active={row.is_active} />,
        },
    ];

    return (
        <>
            <Head title="Monedas" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Monedas"
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> Nueva moneda
                            </Link>
                        </Button>
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
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(row.id)}>Editar</Link>
                            </Button>
                        </div>
                    )}
                />
            </div>
        </>
    );
}

CurrenciesIndex.layout = { breadcrumbs: [{ title: 'Monedas', href: index() }] };
