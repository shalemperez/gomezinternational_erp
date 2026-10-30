import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ApplyAdvanceDialog } from '@/components/erp/apply-advance-dialog';
import { DocumentHeader } from '@/components/erp/document-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatMoney } from '@/lib/format';
import { apply, cancel, index, pdf } from '@/routes/sales/receipts';
import type { AuditEntry } from '@/types/audit';
import type { OpenInvoiceRow, Receipt } from '@/types/sales';

type Props = {
    receipt: Receipt;
    openInvoices: OpenInvoiceRow[];
    history: AuditEntry[];
    can: {
        cancel: boolean;
        apply: boolean;
    };
};

export default function ReceiptShow({
    receipt,
    openInvoices,
    history,
    can: abilities,
}: Props) {
    const [applyOpen, setApplyOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const currency = receipt.currency?.code === 'USD' ? 'USD' : 'DOP';

    const confirmCancel = () =>
        router.post(
            cancel.url(receipt.id),
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
            <Button variant="outline" asChild>
                <a href={pdf.url(receipt.id)} target="_blank" rel="noreferrer">
                    PDF
                </a>
            </Button>
            {abilities.apply && (
                <Button variant="outline" onClick={() => setApplyOpen(true)}>
                    Aplicar anticipo
                </Button>
            )}
            {abilities.cancel && (
                <Button variant="outline" onClick={() => setCancelOpen(true)}>
                    Anular
                </Button>
            )}
        </>
    );

    const meta = [
        { label: 'Cliente', value: receipt.client?.business_name ?? '' },
        { label: 'Fecha', value: formatDate(receipt.receipt_date) },
        { label: 'Método', value: receipt.payment_method_label },
        { label: 'Cuenta', value: receipt.financial_account?.name ?? '' },
        { label: 'Referencia', value: receipt.reference ?? '' },
        { label: 'Moneda', value: receipt.currency?.code ?? '' },
        {
            label: 'Monto',
            value: formatMoney(receipt.amount_received, currency),
        },
        {
            label: 'Retenciones',
            value: formatMoney(
                String(
                    Number(receipt.retention_itbis) +
                        Number(receipt.retention_isr),
                ),
                currency,
            ),
        },
        {
            label: 'Aplicado',
            value: formatMoney(receipt.applied_total, currency),
        },
        {
            label: 'Anticipo',
            value: formatMoney(receipt.unapplied_amount, currency),
        },
    ];

    return (
        <>
            <Head title={`Cobro ${receipt.number}`} />
            <div className="flex flex-col gap-4 p-4">
                <DocumentHeader
                    title="Cobro"
                    number={receipt.number}
                    status={receipt.status}
                    statusLabel={receipt.status_label}
                    meta={meta}
                    actions={actions}
                />

                {receipt.status === 'cancelled' && (
                    <Alert variant="destructive">
                        <AlertTitle>Cobro anulado</AlertTitle>
                        <AlertDescription>
                            {receipt.cancel_reason}
                        </AlertDescription>
                    </Alert>
                )}

                <Tabs defaultValue="aplicaciones">
                    <TabsList>
                        <TabsTrigger value="aplicaciones">
                            Aplicaciones
                        </TabsTrigger>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="aplicaciones" className="pt-4">
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">Factura</th>
                                        <th className="p-2">NCF</th>
                                        <th className="p-2">Origen</th>
                                        <th className="p-2 text-right">
                                            Monto aplicado
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(receipt.applications ?? []).map(
                                        (application) => (
                                            <tr
                                                key={application.id}
                                                className="border-b"
                                            >
                                                <td className="p-2">
                                                    {application.invoice
                                                        ?.number ?? ''}
                                                </td>
                                                <td className="p-2 font-mono">
                                                    {application.invoice?.ncf ??
                                                        ''}
                                                </td>
                                                <td className="p-2">
                                                    {application.source ===
                                                    'advance'
                                                        ? 'Anticipo'
                                                        : 'Cobro'}
                                                </td>
                                                <td className="p-2 text-right">
                                                    {formatMoney(
                                                        application.amount,
                                                        currency,
                                                    )}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                    {(receipt.applications ?? []).length ===
                                        0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="p-4 text-center text-muted-foreground"
                                            >
                                                Sin aplicaciones
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
            </div>

            {abilities.apply && (
                <ApplyAdvanceDialog
                    open={applyOpen}
                    onOpenChange={setApplyOpen}
                    applyUrl={apply.url(receipt.id)}
                    currency={currency}
                    unappliedAmount={receipt.unapplied_amount}
                    openInvoices={openInvoices}
                />
            )}

            {abilities.cancel && (
                <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Anular cobro</DialogTitle>
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
        </>
    );
}

ReceiptShow.layout = {
    breadcrumbs: [{ title: 'Cobros', href: index() }],
};
