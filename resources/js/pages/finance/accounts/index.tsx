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
} from '@/routes/finance/accounts';
import type { FinancialAccount } from '@/types/finance';

type Props = {
    rows: Paginated<FinancialAccount>;
    filters: Record<string, string | undefined>;
};

export default function FinancialAccountIndex({ rows, filters }: Props) {
    const can = useCan();
    const columns: Column<FinancialAccount>[] = [
        {
            key: 'type',
            label: 'Tipo',
            sortable: true,
            render: (row) => row.type_label,
        },
        { key: 'name', label: 'Nombre', sortable: true },
        {
            key: 'bank_name',
            label: 'Banco',
            render: (row) => row.bank_name ?? '—',
        },
        {
            key: 'account_number',
            label: 'Número',
            render: (row) => row.account_number ?? '—',
        },
        {
            key: 'currency',
            label: 'Moneda',
            render: (row) => row.currency?.code ?? '—',
        },
        {
            key: 'is_active',
            label: 'Estado',
            sortable: true,
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
            <Head title="Cuentas bancarias y cajas" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Cuentas bancarias y cajas"
                    actions={
                        <>
                            {can('finance.accounts.view') && (
                                <ExportMenu
                                    xlsxUrl={exportMethod.url({
                                        query: { ...filters, format: 'xlsx' },
                                    })}
                                    csvUrl={exportMethod.url({
                                        query: { ...filters, format: 'csv' },
                                    })}
                                />
                            )}
                            {can('finance.accounts.manage') && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus /> Nueva cuenta
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
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter('type', value)
                            }
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas</SelectItem>
                                <SelectItem value="bank">
                                    Cuentas bancarias
                                </SelectItem>
                                <SelectItem value="cash">Cajas</SelectItem>
                            </SelectContent>
                        </Select>
                    }
                    rowActions={(row) => (
                        <div className="flex justify-end gap-2">
                            {can('finance.accounts.manage') && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(row.id)}>Editar</Link>
                                </Button>
                            )}
                            {can('finance.accounts.manage') && (
                                <ConfirmDelete
                                    url={destroy.url(row.id)}
                                    title="Eliminar cuenta"
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

FinancialAccountIndex.layout = {
    breadcrumbs: [{ title: 'Cuentas bancarias y cajas', href: index() }],
};
