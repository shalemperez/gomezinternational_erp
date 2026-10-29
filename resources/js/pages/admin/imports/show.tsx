import { Head, Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { cancel, confirm, index } from '@/routes/admin/imports';
import type { ImportBatchInfo, ImportBatchRow } from '@/types/imports';

type Props = {
    batch: ImportBatchInfo;
    rows: {
        data: ImportBatchRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    columns: { key: string; label: string }[];
    canConfirm: boolean;
};

const actionLabel = {
    create: 'Crear',
    update: 'Actualizar',
} as const;

export default function ImportShow({
    batch,
    rows,
    columns,
    canConfirm,
}: Props) {
    const fieldLabel = (key: string): string =>
        key === '_row'
            ? 'Fila'
            : (columns.find((c) => c.key === key)?.label ?? key);

    return (
        <>
            <Head title={`Vista previa — ${batch.label}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Vista previa — ${batch.label}`}
                    description={`${batch.total_rows} filas: ${batch.valid_rows} válidas, ${batch.invalid_rows} con errores`}
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href={index()}>Volver</Link>
                            </Button>
                            {batch.status === 'previewed' && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.delete(cancel.url(batch.id))
                                        }
                                    >
                                        Cancelar importación
                                    </Button>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button disabled={!canConfirm}>
                                                Confirmar importación
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>
                                                    Confirmar importación
                                                </AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    {`¿Importar ${batch.valid_rows} filas válidas?`}
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>
                                                    Cancelar
                                                </AlertDialogCancel>
                                                <AlertDialogAction
                                                    onClick={() =>
                                                        router.post(
                                                            confirm.url(
                                                                batch.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Confirmar
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </>
                            )}
                        </div>
                    }
                />

                {batch.status === 'confirmed' && (
                    <p className="text-sm text-muted-foreground">
                        {`Importado el ${formatDateTime(batch.confirmed_at)}: ${batch.created_count} creados, ${batch.updated_count} actualizados`}
                    </p>
                )}

                {batch.invalid_rows > 0 && (
                    <Alert>
                        <AlertDescription>
                            Las filas con errores no se importarán. Corrige el
                            archivo y vuelve a subirlo si las necesitas.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="overflow-x-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Acción</TableHead>
                                {columns.map((column) => (
                                    <TableHead key={column.key}>
                                        {column.label}
                                    </TableHead>
                                ))}
                                <TableHead>Errores</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>{row.row_number}</TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                row.is_valid
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {row.is_valid ? 'Válida' : 'Error'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {row.action
                                            ? actionLabel[row.action]
                                            : '—'}
                                    </TableCell>
                                    {columns.map((column) => (
                                        <TableCell key={column.key}>
                                            {row.data[column.key] ?? ''}
                                        </TableCell>
                                    ))}
                                    <TableCell className="text-red-600 dark:text-red-400">
                                        {Object.entries(row.errors ?? {}).map(
                                            ([field, message]) => (
                                                <div key={field}>
                                                    {fieldLabel(field)}:{' '}
                                                    {message}
                                                </div>
                                            ),
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex flex-wrap gap-1">
                    {rows.links.map((link, i) =>
                        link.url ? (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                asChild
                            >
                                <Link
                                    href={link.url}
                                    preserveScroll
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Button>
                        ) : (
                            <span
                                key={i}
                                className="px-3 py-1 text-sm text-muted-foreground"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </div>
            </div>
        </>
    );
}
