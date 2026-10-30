import { Head, useForm, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ConfirmDelete } from '@/components/erp/confirm-delete';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatDateTime, formatNumber } from '@/lib/format';
import { send } from '@/routes/fiscal/ecf-annulments';
import { destroy, store } from '@/routes/fiscal/ecf-annulments/ranges';
import type {
    EcfAnnulmentHistoryRow,
    EcfAnnulmentPendingRange,
} from '@/types/ecf';

type Props = {
    pending: EcfAnnulmentPendingRange[];
    history: EcfAnnulmentHistoryRow[];
    environment_label: string;
};

const electronicTypes = [
    { value: 'E31', label: 'Factura de Crédito Fiscal Electrónica' },
    { value: 'E32', label: 'Factura de Consumo Electrónica' },
    { value: 'E33', label: 'Nota de Débito Electrónica' },
    { value: 'E34', label: 'Nota de Crédito Electrónica' },
    { value: 'E41', label: 'Comprobante Electrónico de Compras' },
    { value: 'E43', label: 'Comprobante Electrónico para Gastos Menores' },
    {
        value: 'E44',
        label: 'Comprobante Electrónico para Regímenes Especiales',
    },
    { value: 'E45', label: 'Comprobante Electrónico Gubernamental' },
    { value: 'E46', label: 'Comprobante Electrónico para Exportaciones' },
    { value: 'E47', label: 'Comprobante Electrónico para Pagos al Exterior' },
];

const statusVariants: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'outline',
    sending: 'outline',
    sent: 'secondary',
    accepted: 'default',
    rejected: 'destructive',
    failed: 'destructive',
};

export default function EcfAnnulmentIndex({
    pending,
    history,
    environment_label,
}: Props) {
    const [selected, setSelected] = useState<number[]>([]);
    const form = useForm({
        ncf_type: 'E31',
        range_from: '',
        range_to: '',
        reason: '',
    });

    const toggle = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((rangeId) => rangeId !== id),
        );

    const submitRange = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('range_from', 'range_to', 'reason'),
        });
    };

    const sendSelected = () =>
        router.post(
            send.url(),
            { range_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => setSelected([]),
            },
        );

    return (
        <>
            <Head title="Anulación de e-NCF" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Anulación de e-NCF"
                    description={`Rangos de secuencias no utilizadas que se anulan ante la DGII. Ambiente: ${environment_label}.`}
                    actions={
                        <Button
                            onClick={sendSelected}
                            disabled={selected.length === 0}
                        >
                            Enviar anulación ({selected.length})
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Rangos pendientes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10" />
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Rango</TableHead>
                                    <TableHead className="text-right">
                                        Cantidad
                                    </TableHead>
                                    <TableHead>Documento</TableHead>
                                    <TableHead>Motivo</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pending.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="text-center text-muted-foreground"
                                        >
                                            No hay rangos pendientes de anular
                                        </TableCell>
                                    </TableRow>
                                )}
                                {pending.map((range) => (
                                    <TableRow key={range.id}>
                                        <TableCell>
                                            <Checkbox
                                                aria-label={`Seleccionar rango ${range.encf_from}`}
                                                checked={selected.includes(
                                                    range.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        range.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>{range.ncf_type}</TableCell>
                                        <TableCell className="font-mono">
                                            {range.encf_from === range.encf_to
                                                ? range.encf_from
                                                : `${range.encf_from} – ${range.encf_to}`}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatNumber(range.quantity, 0)}
                                        </TableCell>
                                        <TableCell>
                                            {range.document_number ?? '—'}
                                        </TableCell>
                                        <TableCell>{range.reason}</TableCell>
                                        <TableCell className="text-right">
                                            <ConfirmDelete
                                                url={destroy.url(range.id)}
                                                title="Eliminar rango"
                                                description="El rango dejará de estar pendiente de anulación."
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Agregar rango</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submitRange}
                            className="grid gap-4 md:grid-cols-5"
                        >
                            <FormField
                                label="Tipo de e-CF"
                                htmlFor="ncf_type"
                                error={form.errors.ncf_type}
                                required
                            >
                                <Select
                                    value={form.data.ncf_type}
                                    onValueChange={(value) =>
                                        form.setData('ncf_type', value)
                                    }
                                >
                                    <SelectTrigger id="ncf_type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {electronicTypes.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.value} · {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                label="Desde (secuencia)"
                                htmlFor="range_from"
                                error={form.errors.range_from}
                                required
                            >
                                <Input
                                    id="range_from"
                                    type="number"
                                    min={1}
                                    value={form.data.range_from}
                                    onChange={(event) =>
                                        form.setData(
                                            'range_from',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Hasta (secuencia)"
                                htmlFor="range_to"
                                error={form.errors.range_to}
                                required
                            >
                                <Input
                                    id="range_to"
                                    type="number"
                                    min={1}
                                    value={form.data.range_to}
                                    onChange={(event) =>
                                        form.setData(
                                            'range_to',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Motivo"
                                htmlFor="reason"
                                error={form.errors.reason}
                                required
                            >
                                <Input
                                    id="reason"
                                    maxLength={100}
                                    value={form.data.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <div className="flex items-end">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Agregar rango
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Historial de anulaciones</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fecha de envío</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="text-right">
                                        Rangos
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Cantidad
                                    </TableHead>
                                    <TableHead>Mensajes de la DGII</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {history.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="text-center text-muted-foreground"
                                        >
                                            Sin anulaciones enviadas
                                        </TableCell>
                                    </TableRow>
                                )}
                                {history.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            {formatDateTime(row.sent_at)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    statusVariants[
                                                        row.status
                                                    ] ?? 'outline'
                                                }
                                            >
                                                {row.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {row.ranges_count}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatNumber(row.quantity, 0)}
                                        </TableCell>
                                        <TableCell>
                                            {row.messages.join('; ')}
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
