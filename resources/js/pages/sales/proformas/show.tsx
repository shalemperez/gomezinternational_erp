import { Head, Link, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDelete } from '@/components/erp/confirm-delete';
import { DocumentHeader } from '@/components/erp/document-header';
import { DocumentLinesEditor } from '@/components/erp/document-lines-editor';
import { InvoiceFromProformaDialog } from '@/components/erp/invoice-from-proforma-dialog';
import { RecordHistory } from '@/components/erp/record-history';
import { SendEmailDialog } from '@/components/erp/send-email-dialog';
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
import {
    formatDate,
    formatDateTime,
    formatMoney,
    formatNumber,
} from '@/lib/format';
import { useCan } from '@/lib/can';
import {
    approve,
    destroy,
    duplicate,
    edit,
    index,
    markSent,
    pdf,
    reject,
    send,
} from '@/routes/sales/proformas';
import type { AuditEntry } from '@/types/audit';
import type {
    DocumentLineInput,
    ComputedTotals,
    Proforma,
    SentEmailRow,
} from '@/types/sales';

type Props = {
    proforma: Proforma;
    history: AuditEntry[];
    emails: SentEmailRow[];
    can: {
        update: boolean;
        delete: boolean;
        approve: boolean;
        send: boolean;
        invoice: boolean;
    };
};

type Transition = 'approve' | 'reject' | null;

export default function ProformaShow({
    proforma,
    history,
    emails,
    can: abilities,
}: Props) {
    const can = useCan();
    const [transition, setTransition] = useState<Transition>(null);
    const [note, setNote] = useState('');
    const [emailOpen, setEmailOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);
    const currency = proforma.currency?.code === 'USD' ? 'USD' : 'DOP';

    const lines: DocumentLineInput[] = (proforma.lines ?? []).map((line) => ({
        key: String(line.id),
        product_id: line.product_id,
        sku: line.product?.sku,
        description: line.description,
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_percent: line.discount_percent,
    }));

    const computed: ComputedTotals = {
        lines: (proforma.lines ?? []).map((line) => ({
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
            subtotal: proforma.subtotal,
            discount_total: proforma.discount_total,
            taxable_total: proforma.taxable_total,
            itbis_total: proforma.itbis_total,
            additional_tax_total: proforma.additional_tax_total,
            total: proforma.total,
        },
    };

    const confirmTransition = () => {
        if (transition === null) {
            return;
        }
        const action = transition === 'approve' ? approve : reject;
        router.post(
            action.url(proforma.id),
            { note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTransition(null);
                    setNote('');
                },
            },
        );
    };

    const actions = (
        <>
            {abilities.update && (
                <Button variant="outline" asChild>
                    <Link href={edit.url(proforma.id)}>
                        <Pencil /> Editar
                    </Link>
                </Button>
            )}
            {proforma.status === 'draft' && abilities.send && (
                <Button
                    variant="outline"
                    onClick={() =>
                        router.post(
                            markSent.url(proforma.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Marcar como enviada
                </Button>
            )}
            {abilities.approve && (
                <>
                    <Button
                        variant="outline"
                        onClick={() => setTransition('approve')}
                    >
                        Aprobar
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => setTransition('reject')}
                    >
                        Rechazar
                    </Button>
                </>
            )}
            {can('sales.proformas.create') && (
                <Button
                    variant="outline"
                    onClick={() =>
                        router.post(
                            duplicate.url(proforma.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Duplicar
                </Button>
            )}
            <Button variant="outline" asChild>
                <a href={pdf.url(proforma.id)} target="_blank" rel="noreferrer">
                    PDF
                </a>
            </Button>
            {abilities.send && (
                <Button variant="outline" onClick={() => setEmailOpen(true)}>
                    Enviar por correo
                </Button>
            )}
            {abilities.invoice && (
                <Button onClick={() => setInvoiceOpen(true)}>Facturar</Button>
            )}
            {abilities.delete && (
                <ConfirmDelete
                    url={destroy.url(proforma.id)}
                    title="Eliminar proforma"
                    description="Esta acción no se puede deshacer."
                />
            )}
        </>
    );

    const meta = [
        { label: 'Cliente', value: proforma.client?.business_name ?? '' },
        { label: 'RNC', value: proforma.client?.formatted_tax_id ?? '' },
        { label: 'Vendedor', value: proforma.salesperson?.name ?? '' },
        { label: 'Fecha', value: formatDate(proforma.issue_date) },
        { label: 'Válida hasta', value: formatDate(proforma.valid_until) },
        { label: 'Moneda', value: proforma.currency?.code ?? '' },
        ...(Number(proforma.exchange_rate) !== 1
            ? [
                  {
                      label: 'Tasa',
                      value: formatNumber(proforma.exchange_rate, 6),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`Proforma ${proforma.number}`} />
            <div className="flex flex-col gap-4 p-4">
                <DocumentHeader
                    title="Proforma"
                    number={proforma.number}
                    status={proforma.status}
                    statusLabel={proforma.status_label}
                    meta={meta}
                    actions={actions}
                />

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
                            clientId={proforma.client_id}
                            readOnly
                            currency={currency}
                        />
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
                        {formatMoney(proforma.total, currency)}
                    </dd>
                    <dt className="text-muted-foreground">Total en DOP</dt>
                    <dd className="text-right">
                        {formatMoney(proforma.total_base, 'DOP')}
                    </dd>
                </dl>
            </div>

            <Dialog
                open={transition !== null}
                onOpenChange={(open) => !open && setTransition(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {transition === 'approve'
                                ? 'Aprobar proforma'
                                : 'Rechazar proforma'}
                        </DialogTitle>
                    </DialogHeader>
                    <Textarea
                        placeholder="Nota (opcional)"
                        value={note}
                        maxLength={255}
                        onChange={(event) => setNote(event.target.value)}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setTransition(null)}
                        >
                            Cancelar
                        </Button>
                        <Button onClick={confirmTransition}>
                            {transition === 'approve' ? 'Aprobar' : 'Rechazar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <InvoiceFromProformaDialog
                proforma={proforma}
                open={invoiceOpen}
                onOpenChange={setInvoiceOpen}
            />

            <SendEmailDialog
                open={emailOpen}
                onOpenChange={setEmailOpen}
                defaultTo={
                    proforma.client?.email ? [proforma.client.email] : []
                }
                templateKey="proforma"
                submitUrl={send.url(proforma.id)}
                attachmentsLabel={`${proforma.number}.pdf`}
            />
        </>
    );
}

ProformaShow.layout = {
    breadcrumbs: [{ title: 'Proformas', href: index() }],
};
