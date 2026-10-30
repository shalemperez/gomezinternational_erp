import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/erp/confirm-delete';
import { DocumentHeader } from '@/components/erp/document-header';
import { DocumentLinesEditor } from '@/components/erp/document-lines-editor';
import { RecordHistory } from '@/components/erp/record-history';
import { SendEmailDialog } from '@/components/erp/send-email-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    formatNumber,
} from '@/lib/format';
import { create as createCreditNote } from '@/routes/sales/credit-notes';
import {
    cancel,
    destroy,
    edit,
    index,
    issue,
    pdf,
    send,
} from '@/routes/sales/invoices';
import type { AuditEntry } from '@/types/audit';
import type {
    ComputedTotals,
    DocumentLineInput,
    Invoice,
    SentEmailRow,
} from '@/types/sales';

type Props = {
    invoice: Invoice;
    history: AuditEntry[];
    emails: SentEmailRow[];
    can: {
        update: boolean;
        delete: boolean;
        issue: boolean;
        cancel: boolean;
        send: boolean;
        creditOverride: boolean;
        creditNote: boolean;
    };
};

const paymentTermsLabels: Record<string, string> = {
    cash: 'Contado',
    credit: 'Crédito',
};

export default function InvoiceShow({
    invoice,
    history,
    emails,
    can: abilities,
}: Props) {
    const { url } = usePage();
    const [issueOpen, setIssueOpen] = useState(
        () =>
            abilities.issue &&
            new URL(url, 'http://localhost').searchParams.get('emitir') === '1',
    );
    const [creditOverride, setCreditOverride] = useState(false);
    const [creditOverrideReason, setCreditOverrideReason] = useState('');
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [emailOpen, setEmailOpen] = useState(false);
    const currency = invoice.currency?.code === 'USD' ? 'USD' : 'DOP';

    const lines: DocumentLineInput[] = (invoice.lines ?? []).map((line) => ({
        key: String(line.id),
        product_id: line.product_id,
        sku: line.product?.sku,
        description: line.description,
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_percent: line.discount_percent,
    }));

    const computed: ComputedTotals = {
        lines: (invoice.lines ?? []).map((line) => ({
            product_id: line.product_id,
            description: line.description,
            quantity: line.quantity,
            unit_price: line.unit_price,
            discount_percent: line.discount_percent,
            line_subtotal: line.line_subtotal,
            discount_amount: line.discount_amount,
            taxable_base: line.taxable_base,
            itbis_rate: line.itbis_rate,
            itbis_amount: line.itbis_amount,
            additional_taxes: line.additional_taxes,
            additional_tax_total: line.additional_tax_total,
            line_total: line.line_total,
        })),
        totals: {
            subtotal: invoice.subtotal,
            discount_total: invoice.discount_total,
            taxable_total: invoice.taxable_total,
            itbis_total: invoice.itbis_total,
            additional_tax_total: invoice.additional_tax_total,
            total: invoice.total,
        },
    };

    const confirmIssue = () =>
        router.post(
            issue.url(invoice.id),
            {
                credit_override: creditOverride,
                credit_override_reason: creditOverrideReason,
            },
            {
                preserveScroll: true,
                onSuccess: () => setIssueOpen(false),
            },
        );

    const confirmCancel = () =>
        router.post(
            cancel.url(invoice.id),
            { reason: cancelReason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCancelOpen(false);
                    setCancelReason('');
                },
            },
        );

    const actions = (
        <>
            {abilities.update && (
                <Button variant="outline" asChild>
                    <Link href={edit.url(invoice.id)}>
                        <Pencil /> Editar
                    </Link>
                </Button>
            )}
            {abilities.issue && (
                <Button onClick={() => setIssueOpen(true)}>Emitir</Button>
            )}
            {abilities.cancel && (
                <Button variant="outline" onClick={() => setCancelOpen(true)}>
                    Anular
                </Button>
            )}
            <Button variant="outline" asChild>
                <a href={pdf.url(invoice.id)} target="_blank" rel="noreferrer">
                    PDF
                </a>
            </Button>
            {invoice.issued_at && abilities.send && (
                <Button variant="outline" onClick={() => setEmailOpen(true)}>
                    Enviar por correo
                </Button>
            )}
            {abilities.creditNote && (
                <Button variant="outline" asChild>
                    <Link
                        href={createCreditNote.url({
                            query: { invoice: invoice.id },
                        })}
                    >
                        Nota de crédito
                    </Link>
                </Button>
            )}
            {abilities.delete && (
                <ConfirmDelete
                    url={destroy.url(invoice.id)}
                    title="Eliminar borrador de factura"
                    description="Esta acción no se puede deshacer."
                />
            )}
        </>
    );

    const meta = [
        { label: 'NCF', value: invoice.ncf ?? '' },
        { label: 'Tipo', value: invoice.ncf_type_label ?? '' },
        { label: 'Cliente', value: invoice.client_name ?? '' },
        {
            label: 'RNC',
            value:
                invoice.client_tax_id ?? invoice.client?.formatted_tax_id ?? '',
        },
        { label: 'Vendedor', value: invoice.salesperson?.name ?? '' },
        { label: 'Fecha', value: formatDate(invoice.issue_date) },
        { label: 'Vencimiento', value: formatDate(invoice.due_date) },
        {
            label: 'Condición',
            value: paymentTermsLabels[invoice.payment_terms] ?? '',
        },
        { label: 'Moneda', value: invoice.currency?.code ?? '' },
        { label: 'Saldo', value: formatMoney(invoice.balance, currency) },
        ...(Number(invoice.exchange_rate) !== 1
            ? [
                  {
                      label: 'Tasa',
                      value: formatNumber(invoice.exchange_rate, 6),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`Factura ${invoice.number ?? 'borrador'}`} />
            <div className="flex flex-col gap-4 p-4">
                <DocumentHeader
                    title="Factura"
                    number={invoice.number ?? 'Borrador'}
                    status={invoice.status}
                    statusLabel={invoice.status_label}
                    meta={meta}
                    actions={actions}
                />

                {invoice.status === 'cancelled' && (
                    <Alert variant="destructive">
                        <AlertTitle>Factura anulada</AlertTitle>
                        <AlertDescription>
                            {invoice.cancel_reason}
                        </AlertDescription>
                    </Alert>
                )}

                <Tabs defaultValue="lineas">
                    <TabsList>
                        <TabsTrigger value="lineas">Líneas</TabsTrigger>
                        <TabsTrigger value="correos">Correos</TabsTrigger>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="lineas" className="pt-4">
                        <DocumentLinesEditor
                            lines={lines}
                            onChange={() => undefined}
                            computed={computed}
                            clientId={invoice.client_id}
                            readOnly
                            currency={currency}
                        />
                        <div className="mt-4 rounded-md border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">Producto</th>
                                        <th className="p-2 text-right">
                                            Despachado
                                        </th>
                                        <th className="p-2 text-right">
                                            Devuelto
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(invoice.lines ?? []).map((line) => (
                                        <tr key={line.id} className="border-b">
                                            <td className="p-2">
                                                {line.description}
                                            </td>
                                            <td className="p-2 text-right">
                                                {formatNumber(
                                                    line.dispatched_quantity,
                                                    4,
                                                )}
                                            </td>
                                            <td className="p-2 text-right">
                                                {formatNumber(
                                                    line.returned_quantity,
                                                    4,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </TabsContent>
                    <TabsContent value="correos" className="pt-4">
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">Fecha</th>
                                        <th className="p-2">Destinatarios</th>
                                        <th className="p-2">Asunto</th>
                                        <th className="p-2">Estado</th>
                                        <th className="p-2">Usuario</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {emails.map((email) => (
                                        <tr key={email.id} className="border-b">
                                            <td className="p-2">
                                                {formatDateTime(email.sent_at)}
                                            </td>
                                            <td className="p-2">
                                                {email.to.join(', ')}
                                            </td>
                                            <td className="p-2">
                                                {email.subject}
                                            </td>
                                            <td className="p-2">
                                                {email.status}
                                            </td>
                                            <td className="p-2">
                                                {email.user?.name ?? ''}
                                            </td>
                                        </tr>
                                    ))}
                                    {emails.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="p-4 text-center text-muted-foreground"
                                            >
                                                Sin correos enviados
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </TabsContent>
                    <TabsContent value="historial" className="pt-4">
                        <RecordHistory history={history} />
                    </TabsContent>
                </Tabs>

                <dl className="ml-auto grid w-full max-w-sm grid-cols-2 gap-x-4 text-sm">
                    <dt className="font-semibold">Total</dt>
                    <dd className="text-right font-semibold">
                        {formatMoney(invoice.total, currency)}
                    </dd>
                    <dt className="text-muted-foreground">Total en DOP</dt>
                    <dd className="text-right">
                        {formatMoney(invoice.total_base, 'DOP')}
                    </dd>
                </dl>
            </div>

            {abilities.issue && (
                <Dialog open={issueOpen} onOpenChange={setIssueOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Emitir factura</DialogTitle>
                        </DialogHeader>
                        <p className="text-sm text-muted-foreground">
                            Se asignará el NCF y se reservará el inventario.
                            Esta acción no se puede deshacer.
                        </p>
                        {abilities.creditOverride && (
                            <div className="flex flex-col gap-3">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="credit_override"
                                        checked={creditOverride}
                                        onCheckedChange={(checked) =>
                                            setCreditOverride(checked === true)
                                        }
                                    />
                                    <Label htmlFor="credit_override">
                                        Autorizar exceso de límite de crédito
                                    </Label>
                                </div>
                                {creditOverride && (
                                    <Input
                                        placeholder="Motivo"
                                        value={creditOverrideReason}
                                        maxLength={255}
                                        onChange={(event) =>
                                            setCreditOverrideReason(
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setIssueOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button onClick={confirmIssue}>Emitir</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            {abilities.cancel && (
                <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Anular factura</DialogTitle>
                        </DialogHeader>
                        <Textarea
                            placeholder="Motivo"
                            value={cancelReason}
                            maxLength={255}
                            onChange={(event) =>
                                setCancelReason(event.target.value)
                            }
                        />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setCancelOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={cancelReason.trim() === ''}
                                onClick={confirmCancel}
                            >
                                Anular
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            {invoice.issued_at && abilities.send && (
                <SendEmailDialog
                    open={emailOpen}
                    onOpenChange={setEmailOpen}
                    defaultTo={
                        invoice.client?.email ? [invoice.client.email] : []
                    }
                    templateKey="invoice"
                    submitUrl={send.url(invoice.id)}
                    attachmentsLabel={`${invoice.number}.pdf`}
                />
            )}
        </>
    );
}

InvoiceShow.layout = {
    breadcrumbs: [{ title: 'Facturas', href: index() }],
};
