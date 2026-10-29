import { Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

export type Column<Row> = {
    key: string;
    label: string;
    sortable?: boolean;
    className?: string;
    render?: (row: Row) => ReactNode;
};

export type Paginated<Row> = {
    data: Row[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type DataTableProps<Row extends { id: number }> = {
    columns: Column<Row>[];
    rows: Paginated<Row>;
    routeUrl: string;
    filters: Record<string, string | undefined>;
    searchPlaceholder?: string;
    emptyText?: string;
    rowActions?: (row: Row) => ReactNode;
    toolbar?: ReactNode;
};

export function DataTable<Row extends { id: number }>({
    columns,
    rows,
    routeUrl,
    filters,
    searchPlaceholder = 'Buscar…',
    emptyText = 'No hay registros',
    rowActions,
    toolbar,
}: DataTableProps<Row>) {
    const [search, setSearch] = useState(filters.search ?? '');

    const visit = (params: Record<string, string | undefined>) =>
        router.get(
            routeUrl,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        if ((filters.search ?? '') === search) return;
        const t = setTimeout(
            () => visit({ search: search || undefined, page: undefined }),
            300,
        );
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const toggleSort = (key: string) => {
        const dir =
            filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc';
        visit({ sort: key, dir, page: undefined });
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                {toolbar}
                <Input
                    className="max-w-xs"
                    placeholder={searchPlaceholder}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
                <Select
                    value={String(rows.per_page)}
                    onValueChange={(v) =>
                        visit({ per_page: v, page: undefined })
                    }
                >
                    <SelectTrigger className="w-24">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {['15', '25', '50', '100'].map((n) => (
                            <SelectItem key={n} value={n}>
                                {n}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {columns.map((c) => (
                                <TableHead key={c.key} className={c.className}>
                                    {c.sortable ? (
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1"
                                            onClick={() => toggleSort(c.key)}
                                        >
                                            {c.label}
                                            {filters.sort === c.key ? (
                                                filters.dir === 'asc' ? (
                                                    <ArrowUp className="size-3" />
                                                ) : (
                                                    <ArrowDown className="size-3" />
                                                )
                                            ) : (
                                                <ArrowUpDown className="size-3 opacity-40" />
                                            )}
                                        </button>
                                    ) : (
                                        c.label
                                    )}
                                </TableHead>
                            ))}
                            {rowActions && (
                                <TableHead className="w-0 text-right">
                                    Acciones
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.data.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={
                                        columns.length + (rowActions ? 1 : 0)
                                    }
                                    className="py-8 text-center text-muted-foreground"
                                >
                                    {emptyText}
                                </TableCell>
                            </TableRow>
                        )}
                        {rows.data.map((row) => (
                            <TableRow key={row.id}>
                                {columns.map((c) => (
                                    <TableCell
                                        key={c.key}
                                        className={c.className}
                                    >
                                        {c.render
                                            ? c.render(row)
                                            : String(
                                                  (
                                                      row as Record<
                                                          string,
                                                          unknown
                                                      >
                                                  )[c.key] ?? '',
                                              )}
                                    </TableCell>
                                ))}
                                {rowActions && (
                                    <TableCell className="text-right">
                                        {rowActions(row)}
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                <span>
                    {rows.total === 0
                        ? 'Sin resultados'
                        : `Mostrando ${rows.from}–${rows.to} de ${rows.total}`}
                </span>
                <div className="flex gap-1">
                    {rows.links.map((l, i) => (
                        <Button
                            key={i}
                            asChild={!!l.url}
                            variant={l.active ? 'default' : 'outline'}
                            size="sm"
                            disabled={!l.url}
                            className={cn(!l.url && 'opacity-50')}
                        >
                            {l.url ? (
                                <Link href={l.url} preserveState preserveScroll>
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: l.label,
                                        }}
                                    />
                                </Link>
                            ) : (
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: l.label,
                                    }}
                                />
                            )}
                        </Button>
                    ))}
                </div>
            </div>
        </div>
    );
}
