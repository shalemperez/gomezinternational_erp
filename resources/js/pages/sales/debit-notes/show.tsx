import { Head, Link } from '@inertiajs/react';
import { DocumentHeader } from '@/components/erp/document-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { index, pdf } from '@/routes/sales/debit-notes';
import { show as showInvoice } from '@/routes/sales/invoices';
import type { AuditEntry } from '@/types/audit';
import type { DebitNote } from '@/types/sales';

type Props = {
    debitNote: DebitNote;
    history: AuditEntry[];
};

export default function DebitNoteShow({ debitNote, history }: Props) {
    const currency = debitNote.currency?.code === 'USD' ? 'USD' : 'DOP';

    const meta = [
        { label: 'NCF', value: debitNote.ncf },
        { label: 'Tipo', value: debitNote.ncf_type_label },
        {
            label: 'Factura',
            value: debitNote.invoice ? (
                <Link
                    className="underline"
                    href={showInvoice.url(debitNote.invoice.id)}
                >
                    {debitNote.invoice.number}
                </Link>
            ) : (
                ''
            ),
        },
        { label: 'NCF modificado', value: debitNote.modified_ncf ?? '' },
        { label: 'Cliente', value: debitNote.client?.business_name ?? '' },
        { label: 'Fecha', value: formatDate(debitNote.issue_date) },
        { label: 'Motivo', value: debitNote.reason_label },
        { label: 'Moneda', value: debitNote.currency?.code ?? '' },
        { label: 'Total', value: formatMoney(debitNote.total, currency) },
    ];

    return (
        <>
            <Head title={`Nota de débito ${debitNote.number}`} />
            <div className="flex flex-col gap-4 p-4">
                <DocumentHeader
                    title="Nota de débito"
                    number={debitNote.number}
                    status={debitNote.status}
                    statusLabel="Emitida"
                    meta={meta}
                    actions={
                        <Button variant="outline" asChild>
                            <a
                                href={pdf.url(debitNote.id)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                PDF
                            </a>
                        </Button>
                    }
                />

                <Tabs defaultValue="lineas">
                    <TabsList>
                        <TabsTrigger value="lineas">Líneas</TabsTrigger>
                        <TabsTrigger value="historial">Historial</TabsTrigger>
                    </TabsList>
                    <TabsContent value="lineas" className="pt-4">
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Descripción</TableHead>
                                        <TableHead className="text-right">
                                            Cantidad
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Precio
                                        </TableHead>
                                        <TableHead className="text-right">
                                            ITBIS
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(debitNote.lines ?? []).map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell>
                                                {line.description}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatNumber(line.quantity, 4)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(
                                                    line.unit_price,
                                                    currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(
                                                    line.itbis_amount,
                                                    currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(
                                                    line.line_total,
                                                    currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>
                    <TabsContent value="historial" className="pt-4">
                        <RecordHistory history={history} />
                    </TabsContent>
                </Tabs>

                <Button variant="link" className="w-fit px-0" asChild>
                    <Link href={index.url()}>Volver a notas de débito</Link>
                </Button>
            </div>
        </>
    );
}

DebitNoteShow.layout = {
    breadcrumbs: [{ title: 'Notas de débito', href: index() }],
};
