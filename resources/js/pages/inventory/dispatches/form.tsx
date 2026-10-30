import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { index, store, update } from '@/routes/inventory/dispatches';
import type {
    DispatchFormLine,
    DispatchInvoice,
    DispatchInvoiceLine,
    DispatchLotOption,
} from '@/types/inventory';

type Props = {
    invoice: DispatchInvoice;
    lots: DispatchLotOption[];
    dispatch?: {
        id: number;
        dispatch_date: string;
        driver_name: string | null;
        vehicle: string | null;
        notes: string | null;
        lines: DispatchFormLine[];
    };
};

function initialLines(
    invoice: DispatchInvoice,
    dispatch?: Props['dispatch'],
): DispatchFormLine[] {
    if (dispatch) {
        return dispatch.lines;
    }

    return invoice.lines.flatMap((line) => {
        if (line.product.tracks_lots && line.suggested_lots.length > 0) {
            return line.suggested_lots.map((lot) => ({
                invoice_line_id: line.id,
                lot_id: String(lot.lot_id),
                quantity: lot.quantity,
            }));
        }

        return [
            {
                invoice_line_id: line.id,
                lot_id: '',
                quantity: line.product.tracks_lots ? '' : line.remaining,
            },
        ];
    });
}

export default function DispatchForm({ invoice, lots, dispatch }: Props) {
    const title = dispatch ? 'Editar conduce' : 'Nuevo conduce';
    const form = useForm({
        invoice_id: invoice.id,
        dispatch_date:
            dispatch?.dispatch_date ?? new Date().toISOString().slice(0, 10),
        driver_name: dispatch?.driver_name ?? '',
        vehicle: dispatch?.vehicle ?? '',
        notes: dispatch?.notes ?? '',
        lines: initialLines(invoice, dispatch),
    });

    const setLine = (index: number, patch: Partial<DispatchFormLine>) =>
        form.setData(
            'lines',
            form.data.lines.map((row, position) =>
                position === index ? { ...row, ...patch } : row,
            ),
        );

    const addLot = (line: DispatchInvoiceLine) =>
        form.setData('lines', [
            ...form.data.lines,
            { invoice_line_id: line.id, lot_id: '', quantity: '' },
        ]);

    const removeLine = (index: number) =>
        form.setData(
            'lines',
            form.data.lines.filter((_, position) => position !== index),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (dispatch) {
            form.put(update.url(dispatch.id), { preserveScroll: true });
        } else {
            form.post(store.url(), { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={title}
                    description={`Factura ${invoice.number} · ${invoice.client_name}`}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Datos del conduce</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <FormField
                            label="Fecha"
                            htmlFor="dispatch_date"
                            error={form.errors.dispatch_date}
                            required
                        >
                            <Input
                                id="dispatch_date"
                                type="date"
                                value={form.data.dispatch_date}
                                onChange={(event) =>
                                    form.setData(
                                        'dispatch_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField label="Almacén" htmlFor="warehouse">
                            <Input
                                id="warehouse"
                                value={invoice.warehouse.name ?? ''}
                                disabled
                            />
                        </FormField>
                        <FormField
                            label="Chofer"
                            htmlFor="driver_name"
                            error={form.errors.driver_name}
                        >
                            <Input
                                id="driver_name"
                                value={form.data.driver_name}
                                onChange={(event) =>
                                    form.setData(
                                        'driver_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            label="Vehículo"
                            htmlFor="vehicle"
                            error={form.errors.vehicle}
                        >
                            <Input
                                id="vehicle"
                                value={form.data.vehicle}
                                onChange={(event) =>
                                    form.setData('vehicle', event.target.value)
                                }
                            />
                        </FormField>
                        <div className="md:col-span-2">
                            <FormField
                                label="Notas"
                                htmlFor="notes"
                                error={form.errors.notes}
                            >
                                <Textarea
                                    id="notes"
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                    </CardContent>
                </Card>

                {invoice.lines.map((line) => (
                    <Card key={line.id}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {line.product.sku} — {line.product.name}
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Pendiente de despacho:{' '}
                                {formatNumber(line.remaining, 4)}
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {form.data.lines.map((row, index) =>
                                row.invoice_line_id !== line.id ? null : (
                                    <div
                                        key={index}
                                        className="grid items-end gap-2 md:grid-cols-[1fr_12rem_auto]"
                                    >
                                        {line.product.tracks_lots ? (
                                            <FormField
                                                label="Lote"
                                                htmlFor={`lines-${index}-lot`}
                                                error={
                                                    form.errors[
                                                        `lines.${index}.lot_id` as keyof typeof form.errors
                                                    ]
                                                }
                                                required
                                            >
                                                <Select
                                                    value={
                                                        row.lot_id || undefined
                                                    }
                                                    onValueChange={(value) =>
                                                        setLine(index, {
                                                            lot_id: value,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={`lines-${index}-lot`}
                                                    >
                                                        <SelectValue placeholder="Seleccionar lote" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {lots
                                                            .filter(
                                                                (lot) =>
                                                                    lot.product_id ===
                                                                    line.product
                                                                        .id,
                                                            )
                                                            .map((lot) => (
                                                                <SelectItem
                                                                    key={lot.id}
                                                                    value={String(
                                                                        lot.id,
                                                                    )}
                                                                >
                                                                    {lot.code}
                                                                    {lot.expires_on
                                                                        ? ` · vence ${lot.expires_on}`
                                                                        : ''}
                                                                    {lot.expired
                                                                        ? ' · VENCIDO'
                                                                        : ''}
                                                                    {` · existencia ${formatNumber(lot.quantity, 4)}`}
                                                                </SelectItem>
                                                            ))}
                                                    </SelectContent>
                                                </Select>
                                            </FormField>
                                        ) : (
                                            <div />
                                        )}
                                        <FormField
                                            label="Cantidad"
                                            htmlFor={`lines-${index}-quantity`}
                                            error={
                                                form.errors[
                                                    `lines.${index}.quantity` as keyof typeof form.errors
                                                ]
                                            }
                                            required
                                        >
                                            <Input
                                                id={`lines-${index}-quantity`}
                                                inputMode="decimal"
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </FormField>
                                        {line.product.tracks_lots && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label="Quitar lote"
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </div>
                                ),
                            )}
                            {line.product.tracks_lots && (
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => addLot(line)}
                                    >
                                        <Plus /> Añadir lote
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}

                {form.errors.lines && (
                    <p className="text-sm text-destructive">
                        {form.errors.lines}
                    </p>
                )}

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        Guardar borrador
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={index()}>Cancelar</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

DispatchForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Conduces', href: index() },
        {
            title: props.dispatch ? 'Editar conduce' : 'Nuevo conduce',
            href: '#',
        },
    ],
});
