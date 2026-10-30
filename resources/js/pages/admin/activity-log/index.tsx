import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { ExportMenu } from '@/components/erp/export-menu';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
import { useCan } from '@/lib/can';
import { formatDateTime } from '@/lib/format';
import { exportMethod, index } from '@/routes/admin/activity-log';
import type { ActivityRow } from '@/types/admin';

type Props = {
    rows: Paginated<ActivityRow>;
    filters: Record<string, string | undefined>;
    users: { id: number; name: string }[];
    logNames: string[];
    events: string[];
};

const show = (value: unknown): string =>
    value === null || value === undefined || value === ''
        ? '—'
        : typeof value === 'string'
          ? value
          : JSON.stringify(value);

export default function ActivityLogIndex({
    rows,
    filters,
    users,
    logNames,
    events,
}: Props) {
    const can = useCan();
    const [detail, setDetail] = useState<ActivityRow | null>(null);

    const changeFilter = (key: string, value: string | undefined) =>
        router.get(
            index.url(),
            {
                ...filters,
                [key]:
                    value === undefined || value === 'all' || value === ''
                        ? undefined
                        : value,
                page: undefined,
            },
            { preserveState: true, replace: true },
        );

    const columns: Column<ActivityRow>[] = [
        {
            key: 'created_at',
            label: 'Fecha',
            sortable: true,
            render: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'causer',
            label: 'Usuario',
            render: (row) => (
                <span>
                    {row.causer?.name ?? 'Sistema'}
                    {row.impersonator && (
                        <Badge
                            variant="outline"
                            className="ml-2"
                            title={row.impersonator.name}
                        >
                            suplantado
                        </Badge>
                    )}
                </span>
            ),
        },
        {
            key: 'log_name',
            label: 'Registro',
            render: (row) => show(row.log_name),
        },
        { key: 'event', label: 'Evento', render: (row) => show(row.event) },
        {
            key: 'description',
            label: 'Descripción',
            render: (row) => row.description,
        },
        { key: 'subject', label: 'Objeto', render: (row) => show(row.subject) },
    ];

    return (
        <>
            <Head title="Bitácora de actividad" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Bitácora de actividad"
                    actions={
                        can('admin.activity-log.export') && (
                            <ExportMenu
                                xlsxUrl={exportMethod.url({
                                    query: { ...filters, format: 'xlsx' },
                                })}
                                csvUrl={exportMethod.url({
                                    query: { ...filters, format: 'csv' },
                                })}
                            />
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar en descripción…"
                    rowActions={(row) => (
                        <Dialog
                            open={detail?.id === row.id}
                            onOpenChange={(open) =>
                                setDetail(open ? row : null)
                            }
                        >
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    Detalles
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>{row.description}</DialogTitle>
                                </DialogHeader>
                                {row.changes.length > 0 && (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Campo</TableHead>
                                                <TableHead>Antes</TableHead>
                                                <TableHead>Después</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {row.changes.map((change) => (
                                                <TableRow key={change.field}>
                                                    <TableCell>
                                                        {change.field}
                                                    </TableCell>
                                                    <TableCell>
                                                        {show(change.old)}
                                                    </TableCell>
                                                    <TableCell>
                                                        {show(change.new)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                                {Object.keys(row.properties).length > 0 && (
                                    <pre className="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs">
                                        {JSON.stringify(
                                            row.properties,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                )}
                                {row.impersonator && (
                                    <p className="text-xs text-muted-foreground">
                                        Suplantado por {row.impersonator.name}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    IP {show(row.ip)} · Request{' '}
                                    {show(row.request_id)}
                                </p>
                            </DialogContent>
                        </Dialog>
                    )}
                    toolbar={
                        <>
                            <Select
                                value={filters.causer_id ?? 'all'}
                                onValueChange={(value) =>
                                    changeFilter('causer_id', value)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="Todos los usuarios" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Todos los usuarios
                                    </SelectItem>
                                    {users.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.log_name ?? 'all'}
                                onValueChange={(value) =>
                                    changeFilter('log_name', value)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    {logNames.map((logName) => (
                                        <SelectItem
                                            key={logName}
                                            value={logName}
                                        >
                                            {logName}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.event ?? 'all'}
                                onValueChange={(value) =>
                                    changeFilter('event', value)
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    {events.map((event) => (
                                        <SelectItem key={event} value={event}>
                                            {event}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                className="w-40"
                                placeholder="Ej. Client"
                                aria-label="Tipo de registro"
                                defaultValue={filters.subject_type ?? ''}
                                onBlur={(event) =>
                                    changeFilter(
                                        'subject_type',
                                        event.currentTarget.value,
                                    )
                                }
                            />
                            <Input
                                className="w-24"
                                placeholder="ID"
                                aria-label="ID del registro"
                                defaultValue={filters.subject_id ?? ''}
                                onBlur={(event) =>
                                    changeFilter(
                                        'subject_id',
                                        event.currentTarget.value,
                                    )
                                }
                            />
                            <span className="text-sm text-muted-foreground">
                                Desde
                            </span>
                            <Input
                                type="date"
                                className="w-40"
                                aria-label="Desde"
                                defaultValue={filters.date_from ?? ''}
                                onChange={(event) =>
                                    changeFilter(
                                        'date_from',
                                        event.currentTarget.value,
                                    )
                                }
                            />
                            <span className="text-sm text-muted-foreground">
                                Hasta
                            </span>
                            <Input
                                type="date"
                                className="w-40"
                                aria-label="Hasta"
                                defaultValue={filters.date_to ?? ''}
                                onChange={(event) =>
                                    changeFilter(
                                        'date_to',
                                        event.currentTarget.value,
                                    )
                                }
                            />
                        </>
                    }
                />
            </div>
        </>
    );
}

ActivityLogIndex.layout = {
    breadcrumbs: [{ title: 'Bitácora', href: index() }],
};
