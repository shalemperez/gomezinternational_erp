import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { Textarea } from '@/components/ui/textarea';
import { useTotalsPreview } from '@/hooks/use-totals-preview';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { index, store } from '@/routes/sales/credit-notes';
import { show as showInvoice } from '@/routes/sales/invoices';
import type { CreditNoteLot, Invoice, InvoiceLine } from '@/types/sales';

type NoteInvoice = Omit<Invoice, 'lines'> & {
    lines: (InvoiceLine & {
        product: {
            id: number;
            sku: string;
            name: string;
            tracks_lots: boolean;
        };
    })[];
};

type Props = {
    invoice: NoteInvoice;
    reasons: { value: string; label: string }[];
    warehouses: { id: number; name: string }[];
    lots: CreditNoteLot[];
    today: string;
};

type NoteLine = {
    key: string;
    included: boolean;
    invoice_line_id: number;
    product_id: number;
    sku: string;
    name: string;
    tracks_lots: boolean;
    itbis_rate: string;
    invoiced: string;
    dispatched: string;
    returned: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    lot_id: string;
};

type LinePayload = {
    invoice_line_id: number;
    product_id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    lot_id: string;
};

type CreditNoteFormData = {
    invoice_id: number;
    reason: string;
    warehouse_id: string;
    notes: string;
    lines: LinePayload[];
};

const NONE = 'none';

export default function CreditNoteForm({
    invoice,
    reasons,
    warehouses,
    lots,
    today,
}: Props) {
    const currency = invoice.currency?.code === 'USD' ? 'USD' : 'DOP';
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [lines, setLines] = useState<NoteLine[]>(() =>
        invoice.lines.map((line) => ({
            key: String(line.id),
            included: true,
            invoice_line_id: line.id,
            product_id: line.product_id,
            sku: line.product.sku,
            name: line.product.name,
            tracks_lots: line.product.tracks_lots,
            itbis_rate: line.itbis_rate,
            invoiced: line.quantity,
            dispatched: line.dispatched_quantity,
            returned: line.returned_quantity,
            description: line.description,
            quantity: line.quantity,
            unit_price: line.unit_price,
            discount_percent: line.discount_percent,
            lot_id: '',
        })),
    );

    const form = useForm<CreditNoteFormData>({
        invoice_id: invoice.id,
        reason: 'discount',
        warehouse_id: String(invoice.warehouse_id ?? ''),
        notes: '',
        lines: [],
    });

    const isReturn = form.data.reason === 'return';
    const errors = form.errors as Record<string, string | undefined>;

    const included = useMemo(
        () => lines.filter((line) => line.included),
        [lines],
    );
    const preview = useMemo(
        () =>
            included.map((line) => ({
                key: line.key,
                product_id: line.product_id,
                sku: line.sku,
                description: line.description,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_percent: line.discount_percent,
                itbis_rate: line.itbis_rate,
            })),
        [included],
    );
    const { computed } = useTotalsPreview(preview);

    const updateLine = (key: string, patch: Partial<NoteLine>) =>
        setLines((current) =>
            current.map((line) =>
                line.key === key ? { ...line, ...patch } : line,
            ),
        );

    const submit = () => {
        form.transform((data) => ({
            ...data,
            warehouse_id: data.reason === 'return' ? data.warehouse_id : '',
            lines: included.map((line) => ({
                invoice_line_id: line.invoice_line_id,
                product_id: line.product_id,
                description: line.description,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_percent: line.discount_percent,
                lot_id: data.reason === 'return' ? line.lot_id : '',
            })),
        }));
        form.post(store.url(), {
            onFinish: () => setConfirmOpen(false),
        });
    };

    const lotsFor = (productId: number) =>
        lots.filter((lot) => lot.product_id === productId);

    return (
        <>
            <Head title="Nueva nota de crédito" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Nueva nota de crédito"
                    description={`Factura ${invoice.number ?? ''} · NCF ${invoice.ncf ?? ''}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={showInvoice.url(invoice.id)}>
                                Cancelar
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Factura de referencia</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">
                                    Cliente
                                </dt>
                                <dd>{invoice.client_name ?? ''}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Fecha</dt>
                                <dd>{formatDate(invoice.issue_date)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Total</dt>
                                <dd>{formatMoney(invoice.total, currency)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Saldo</dt>
                                <dd>
                                    {formatMoney(invoice.balance, currency)}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Datos de la nota</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <FormField
                            label="Motivo"
                            htmlFor="reason"
                            error={form.errors.reason}
                            required
                        >
                            <Select
                                value={form.data.reason}
                                onValueChange={(value) =>
                                    form.setData('reason', value)
                                }
                            >
                                <SelectTrigger id="reason">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {reasons.map((reason) => (
                                        <SelectItem
                                            key={reason.value}
                                            value={reason.value}
                                        >
                                            {reason.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            label="Fecha de emisión"
                            htmlFor="issue_date"
                            hint="Se asigna al emitir la nota."
                        >
                            <Input
                                id="issue_date"
                                value={formatDate(today)}
                                readOnly
                                disabled
                            />
                        </FormField>
                        {isReturn && (
                            <FormField
                                label="Almacén"
                                htmlFor="warehouse_id"
                                error={form.errors.warehouse_id}
                                required
                            >
                                <Select
                                    value={form.data.warehouse_id || NONE}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'warehouse_id',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger id="warehouse_id">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {warehouses.map((warehouse) => (
                                            <SelectItem
                                                key={warehouse.id}
                                                value={String(warehouse.id)}
                                            >
                                                {warehouse.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        )}
                        <FormField
                            label="Notas"
                            htmlFor="notes"
                            error={form.errors.notes}
                            className="grid gap-2 md:col-span-2"
                        >
                            <Textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </FormField>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Líneas a acreditar</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Incluir</TableHead>
                                        <TableHead>Producto</TableHead>
                                        <TableHead className="text-right">
                                            Facturada
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Despachada
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Devuelta
                                        </TableHead>
                                        <TableHead>Cantidad</TableHead>
                                        <TableHead>Precio</TableHead>
                                        {isReturn && (
                                            <TableHead>Lote</TableHead>
                                        )}
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lines.map((line) => {
                                        const includedIndex =
                                            included.findIndex(
                                                (item) => item.key === line.key,
                                            );
                                        const rowTotal =
                                            includedIndex >= 0
                                                ? computed?.lines[includedIndex]
                                                      ?.line_total
                                                : undefined;
                                        const prefix = `lines.${includedIndex}.`;

                                        return (
                                            <TableRow key={line.key}>
                                                <TableCell>
                                                    <Checkbox
                                                        checked={line.included}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    included:
                                                                        checked ===
                                                                        true,
                                                                },
                                                            )
                                                        }
                                                        aria-label={`Incluir ${line.sku}`}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-mono text-xs">
                                                        {line.sku}
                                                    </div>
                                                    <Input
                                                        className="mt-1"
                                                        aria-label="Descripción"
                                                        value={line.description}
                                                        onChange={(event) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    description:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatNumber(
                                                        line.invoiced,
                                                        4,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatNumber(
                                                        line.dispatched,
                                                        4,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatNumber(
                                                        line.returned,
                                                        4,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        inputMode="decimal"
                                                        className="w-28"
                                                        aria-label="Cantidad"
                                                        disabled={
                                                            !line.included
                                                        }
                                                        value={line.quantity}
                                                        onChange={(event) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    quantity:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                    {errors[
                                                        `${prefix}quantity`
                                                    ] && (
                                                        <p className="text-xs text-red-600">
                                                            {
                                                                errors[
                                                                    `${prefix}quantity`
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        inputMode="decimal"
                                                        className="w-28"
                                                        aria-label="Precio"
                                                        disabled={
                                                            !line.included
                                                        }
                                                        value={line.unit_price}
                                                        onChange={(event) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    unit_price:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                    {errors[
                                                        `${prefix}unit_price`
                                                    ] && (
                                                        <p className="text-xs text-red-600">
                                                            {
                                                                errors[
                                                                    `${prefix}unit_price`
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </TableCell>
                                                {isReturn && (
                                                    <TableCell>
                                                        {line.tracks_lots ? (
                                                            <Select
                                                                value={
                                                                    line.lot_id ||
                                                                    NONE
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateLine(
                                                                        line.key,
                                                                        {
                                                                            lot_id:
                                                                                value ===
                                                                                NONE
                                                                                    ? ''
                                                                                    : value,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger
                                                                    className="w-44"
                                                                    aria-label="Lote"
                                                                >
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem
                                                                        value={
                                                                            NONE
                                                                        }
                                                                    >
                                                                        Sin lote
                                                                    </SelectItem>
                                                                    {lotsFor(
                                                                        line.product_id,
                                                                    ).map(
                                                                        (
                                                                            lot,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    lot.id
                                                                                }
                                                                                value={String(
                                                                                    lot.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    lot.code
                                                                                }
                                                                                {lot.expires_on
                                                                                    ? ` · vence ${formatDate(lot.expires_on)}`
                                                                                    : ''}
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                        {errors[
                                                            `${prefix}lot_id`
                                                        ] && (
                                                            <p className="text-xs text-red-600">
                                                                {
                                                                    errors[
                                                                        `${prefix}lot_id`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                )}
                                                <TableCell className="text-right">
                                                    {rowTotal
                                                        ? formatMoney(
                                                              rowTotal,
                                                              currency,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                        {errors['lines'] && (
                            <p className="mt-2 text-sm text-red-600">
                                {errors['lines']}
                            </p>
                        )}

                        <div className="mt-4 ml-auto w-full max-w-sm space-y-1 text-sm">
                            <div className="flex justify-between">
                                <span>Subtotal</span>
                                <span>
                                    {computed
                                        ? formatMoney(
                                              computed.totals.subtotal,
                                              currency,
                                          )
                                        : '—'}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span>ITBIS</span>
                                <span>
                                    {computed
                                        ? formatMoney(
                                              computed.totals.itbis_total,
                                              currency,
                                          )
                                        : '—'}
                                </span>
                            </div>
                            <div className="flex justify-between font-semibold">
                                <span>Total</span>
                                <span>
                                    {computed
                                        ? formatMoney(
                                              computed.totals.total,
                                              currency,
                                          )
                                        : '—'}
                                </span>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-2">
                            <Button
                                type="button"
                                disabled={
                                    form.processing || included.length === 0
                                }
                                onClick={() => setConfirmOpen(true)}
                            >
                                Emitir nota de crédito
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Emitir nota de crédito
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Se asignará un NCF B04. Esta acción no se puede
                            deshacer.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={form.processing}
                            onClick={submit}
                        >
                            Emitir nota de crédito
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

CreditNoteForm.layout = {
    breadcrumbs: [{ title: 'Notas de crédito', href: index() }],
};
