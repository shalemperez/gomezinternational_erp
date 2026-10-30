import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { formatDateTime } from '@/lib/format';
import { index, preview, show } from '@/routes/admin/imports';
import type { ImportBatchInfo, ImporterInfo } from '@/types/imports';

type Props = {
    importers: ImporterInfo[];
    batches: ImportBatchInfo[];
};

const statusVariant = {
    previewed: 'secondary',
    confirmed: 'default',
    cancelled: 'destructive',
} as const;

export default function ImportsIndex({ importers, batches }: Props) {
    return (
        <>
            <Head title="Importar datos" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Importar datos" />

                <div className="grid gap-4 md:grid-cols-2">
                    {importers.map((importer) => (
                        <Card key={importer.type}>
                            <CardHeader>
                                <CardTitle>{importer.label}</CardTitle>
                                <CardDescription>
                                    <a
                                        href={importer.templateUrl}
                                        className="underline"
                                    >
                                        Descargar plantilla
                                    </a>
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <ul className="list-inside list-disc text-sm text-muted-foreground">
                                    {importer.columns.map((column) => (
                                        <li key={column.label}>
                                            {column.label}
                                            {column.required && ' *'}
                                        </li>
                                    ))}
                                </ul>
                                <Form
                                    {...preview.form(importer.type)}
                                    encType="multipart/form-data"
                                    className="flex flex-col gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            {importer.meta.price_lists && (
                                                <FormField
                                                    label="Lista de precios"
                                                    htmlFor="price_list_id"
                                                    error={errors.price_list_id}
                                                    required
                                                >
                                                    <Select
                                                        name="price_list_id"
                                                        required
                                                    >
                                                        <SelectTrigger
                                                            id="price_list_id"
                                                            className="w-full"
                                                        >
                                                            <SelectValue placeholder="Seleccione una lista" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {importer.meta.price_lists.map(
                                                                (priceList) => (
                                                                    <SelectItem
                                                                        key={
                                                                            priceList.id
                                                                        }
                                                                        value={String(
                                                                            priceList.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            priceList.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </FormField>
                                            )}
                                            <input
                                                type="file"
                                                name="file"
                                                accept=".xlsx,.xls,.csv"
                                                required
                                                className="text-sm"
                                            />
                                            <InputError message={errors.file} />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="self-start"
                                            >
                                                Subir y previsualizar
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Importaciones recientes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Archivo</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Válidas/Inválidas</TableHead>
                                    <TableHead>Creados/Actualizados</TableHead>
                                    <TableHead>Usuario</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {batches.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="text-center text-muted-foreground"
                                        >
                                            No hay importaciones
                                        </TableCell>
                                    </TableRow>
                                )}
                                {batches.map((batch) => (
                                    <TableRow key={batch.id}>
                                        <TableCell>
                                            {formatDateTime(batch.created_at)}
                                        </TableCell>
                                        <TableCell>{batch.label}</TableCell>
                                        <TableCell>
                                            {batch.original_name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    statusVariant[batch.status]
                                                }
                                            >
                                                {batch.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {batch.valid_rows} /{' '}
                                            {batch.invalid_rows}
                                        </TableCell>
                                        <TableCell>
                                            {batch.created_count} /{' '}
                                            {batch.updated_count}
                                        </TableCell>
                                        <TableCell>{batch.user.name}</TableCell>
                                        <TableCell>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={show(batch.id)}>
                                                    Ver
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Importar datos', href: index() }],
};
