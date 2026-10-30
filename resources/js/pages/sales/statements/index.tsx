import { Head, Link, router } from '@inertiajs/react';
import { useState, type KeyboardEvent } from 'react';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/format';
import { exportMethod, index, show } from '@/routes/sales/statements';
import type { AgingRow, AgingTotals } from '@/types/sales';

type Props = {
    aging: { rows: AgingRow[]; totals: AgingTotals };
    filters: {
        as_of: string;
        salesperson_id: number | null;
        search: string;
    };
    salespeople: { id: number; name: string }[];
    asOf: string;
};

export default function StatementIndex({ aging, filters, salespeople }: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (changes: Record<string, string | undefined>) =>
        router.get(
            index.url(),
            {
                as_of: filters.as_of,
                salesperson_id: filters.salesperson_id ?? undefined,
                search: filters.search || undefined,
                ...changes,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const handleSearchKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            applyFilters({ search: search.trim() || undefined });
        }
    };

    const exportQuery = {
        as_of: filters.as_of,
        salesperson_id: filters.salesperson_id ?? undefined,
        search: filters.search || undefined,
    };

    return (
        <>
            <Head title="Estados de cuenta y antigüedad" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Estados de cuenta y antigüedad"
                    description="Saldos abiertos de facturas por días de atraso"
                    actions={
                        <ExportMenu
                            xlsxUrl={exportMethod.url({
                                query: { ...exportQuery, format: 'xlsx' },
                            })}
                            csvUrl={exportMethod.url({
                                query: { ...exportQuery, format: 'csv' },
                            })}
                        />
                    }
                />
                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex flex-col gap-1">
                        <label className="text-sm font-medium" htmlFor="as-of">
                            Fecha de corte
                        </label>
                        <Input
                            id="as-of"
                            type="date"
                            className="w-44"
                            value={filters.as_of}
                            onChange={(event) =>
                                applyFilters({
                                    as_of: event.target.value || undefined,
                                })
                            }
                        />
                    </div>
                    {salespeople.length > 0 && (
                        <div className="flex flex-col gap-1">
                            <span className="text-sm font-medium">
                                Vendedor
                            </span>
                            <Select
                                value={
                                    filters.salesperson_id
                                        ? String(filters.salesperson_id)
                                        : 'all'
                                }
                                onValueChange={(value) =>
                                    applyFilters({
                                        salesperson_id:
                                            value === 'all' ? undefined : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los vendedores
                                    </SelectItem>
                                    {salespeople.map((salesperson) => (
                                        <SelectItem
                                            key={salesperson.id}
                                            value={String(salesperson.id)}
                                        >
                                            {salesperson.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="flex flex-col gap-1">
                        <label
                            className="text-sm font-medium"
                            htmlFor="statement-search"
                        >
                            Buscar cliente
                        </label>
                        <Input
                            id="statement-search"
                            className="w-64"
                            placeholder="Código o nombre, Enter para buscar"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={handleSearchKey}
                        />
                    </div>
                </div>
                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Código</TableHead>
                                <TableHead>Cliente</TableHead>
                                <TableHead>Vendedor</TableHead>
                                <TableHead>Moneda</TableHead>
                                <TableHead className="text-right">
                                    Vigente
                                </TableHead>
                                <TableHead className="text-right">
                                    1–30
                                </TableHead>
                                <TableHead className="text-right">
                                    31–60
                                </TableHead>
                                <TableHead className="text-right">
                                    61–90
                                </TableHead>
                                <TableHead className="text-right">
                                    +90
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="text-right">
                                    Total RD$
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {aging.rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={11}
                                        className="text-center text-muted-foreground"
                                    >
                                        No hay saldos abiertos
                                    </TableCell>
                                </TableRow>
                            )}
                            {aging.rows.map((row) => {
                                const money = (value: string) =>
                                    formatMoney(
                                        value,
                                        row.currency_code as 'DOP' | 'USD',
                                    );

                                return (
                                    <TableRow
                                        key={`${row.client_id}-${row.currency_code}`}
                                    >
                                        <TableCell className="font-mono">
                                            {row.client_code}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                className="underline"
                                                href={show.url(row.client_id)}
                                            >
                                                {row.client_name}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {row.salesperson ?? ''}
                                        </TableCell>
                                        <TableCell>
                                            {row.currency_code}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {money(row.current)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {money(row.d1_30)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {money(row.d31_60)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {money(row.d61_90)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {money(row.d90_plus)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {money(row.total)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(row.total_base, 'DOP')}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                        {aging.rows.length > 0 && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="font-medium"
                                    >
                                        Totales (RD$)
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            aging.totals.current,
                                            'DOP',
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(aging.totals.d1_30, 'DOP')}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            aging.totals.d31_60,
                                            'DOP',
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            aging.totals.d61_90,
                                            'DOP',
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            aging.totals.d90_plus,
                                            'DOP',
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(aging.totals.total, 'DOP')}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(
                                            aging.totals.total_base,
                                            'DOP',
                                        )}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>
            </div>
        </>
    );
}

StatementIndex.layout = {
    breadcrumbs: [{ title: 'Estados de cuenta', href: index() }],
};
