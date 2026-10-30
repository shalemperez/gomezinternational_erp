import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { DocumentLinesEditor } from '@/components/erp/document-lines-editor';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useTotalsPreview } from '@/hooks/use-totals-preview';
import { formatDate } from '@/lib/format';
import { index, store } from '@/routes/sales/debit-notes';
import { show as showInvoice } from '@/routes/sales/invoices';
import type { DocumentLineInput, Invoice } from '@/types/sales';

type Props = {
    invoice: Invoice;
    reasons: { value: string; label: string }[];
    today: string;
};

type DebitNoteFormData = {
    invoice_id: number;
    issue_date: string;
    reason: string;
    notes: string;
    lines: DocumentLineInput[];
};

export default function DebitNoteForm({ invoice, reasons, today }: Props) {
    const { data, setData, transform, post, processing, errors } =
        useForm<DebitNoteFormData>({
            invoice_id: invoice.id,
            issue_date: today,
            reason: reasons[0]?.value ?? '',
            notes: '',
            lines: [],
        });

    const { computed } = useTotalsPreview(data.lines);
    const currency = invoice.currency?.code === 'USD' ? 'USD' : 'DOP';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        transform((current) => ({
            ...current,
            lines: current.lines.map((line) => ({
                product_id: line.product_id,
                description: line.description,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_percent: line.discount_percent,
            })),
        }));
        post(store.url());
    };

    const title = 'Nueva nota de débito';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={title}
                    description={`Factura ${invoice.number ?? ''} · NCF ${invoice.ncf ?? ''} · Emitida ${formatDate(invoice.issue_date)}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={showInvoice.url(invoice.id)}>
                                Cancelar
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <FormField
                        label="Motivo"
                        htmlFor="reason"
                        required
                        error={errors.reason}
                    >
                        <Select
                            value={data.reason}
                            onValueChange={(value) => setData('reason', value)}
                        >
                            <SelectTrigger id="reason" className="w-full">
                                <SelectValue placeholder="Selecciona el motivo" />
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
                        label="Fecha"
                        htmlFor="issue_date"
                        required
                        error={errors.issue_date}
                    >
                        <Input
                            id="issue_date"
                            type="date"
                            value={data.issue_date}
                            onChange={(event) =>
                                setData('issue_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Notas"
                        htmlFor="notes"
                        error={errors.notes}
                    >
                        <Textarea
                            id="notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                </div>

                <FormField label="Líneas" htmlFor="lines" error={errors.lines}>
                    <DocumentLinesEditor
                        lines={data.lines}
                        onChange={(lines) => setData('lines', lines)}
                        computed={computed}
                        clientId={invoice.client_id}
                        currency={currency}
                    />
                </FormField>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Emitir nota de débito
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link href={showInvoice.url(invoice.id)}>Cancelar</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

DebitNoteForm.layout = {
    breadcrumbs: [{ title: 'Notas de débito', href: index() }],
};
