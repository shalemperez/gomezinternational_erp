import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { ClientPicker } from '@/components/erp/client-picker';
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
import { index, show, store, update } from '@/routes/sales/invoices';
import type { DocumentLineInput, Invoice, PickedClient } from '@/types/sales';

type Props = {
    invoice?: Invoice;
    currencies: { id: number; code: string }[];
    salespeople: { id: number; name: string }[];
    warehouses: { id: number; name: string }[];
    paymentTerms: { value: string; label: string }[];
    defaultWarehouseId: number | null;
    lockSalesperson: boolean;
    defaultCurrencyId: number;
    today: string;
};

type InvoiceFormData = {
    client_id: number | null;
    salesperson_id: number | null;
    warehouse_id: number | null;
    currency_id: number | null;
    issue_date: string;
    payment_terms: string;
    credit_days: number;
    notes: string;
    lines: DocumentLineInput[];
};

export default function InvoiceForm({
    invoice,
    currencies,
    salespeople,
    warehouses,
    paymentTerms,
    defaultWarehouseId,
    lockSalesperson,
    defaultCurrencyId,
    today,
}: Props) {
    const { data, setData, transform, post, put, processing, errors } =
        useForm<InvoiceFormData>({
            client_id: invoice?.client_id ?? null,
            salesperson_id:
                invoice?.salesperson_id ??
                (lockSalesperson ? (salespeople[0]?.id ?? null) : null),
            warehouse_id: invoice?.warehouse_id ?? defaultWarehouseId,
            currency_id: invoice?.currency_id ?? defaultCurrencyId,
            issue_date: invoice?.issue_date ?? today,
            payment_terms: invoice?.payment_terms ?? 'credit',
            credit_days: invoice?.credit_days ?? 0,
            notes: invoice?.notes ?? '',
            lines: (invoice?.lines ?? []).map((line) => ({
                key: String(line.id),
                product_id: line.product_id,
                sku: line.product?.sku,
                description: line.description,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_percent: line.discount_percent,
            })),
        });

    const { computed } = useTotalsPreview(data.lines);
    const currencyCode =
        currencies.find((currency) => currency.id === data.currency_id)?.code ??
        'DOP';
    const currency = currencyCode === 'USD' ? 'USD' : 'DOP';
    const isCash = data.payment_terms === 'cash';

    const [selectedClient, setSelectedClient] = useState<PickedClient | null>(
        invoice?.client ?? null,
    );

    const pickClient = (client: PickedClient) => {
        setSelectedClient(client);
        setData((current) => ({
            ...current,
            client_id: client.id,
            currency_id:
                currencies.find((item) => item.code === client.currency_code)
                    ?.id ?? current.currency_id,
            credit_days: client.credit_days,
        }));
    };

    const setPaymentTerms = (value: string) =>
        setData((current) => ({
            ...current,
            payment_terms: value,
            credit_days: value === 'cash' ? 0 : current.credit_days,
        }));

    const applyTransform = () =>
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

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        applyTransform();
        if (invoice) {
            put(update.url(invoice.id));
        } else {
            post(store.url());
        }
    };

    const issue = () => {
        if (invoice) {
            applyTransform();
            put(update.url(invoice.id), {
                preserveScroll: true,
                onSuccess: () =>
                    router.visit(show.url(invoice.id), {
                        data: { emitir: '1' },
                    }),
            });
        }
    };

    const title = invoice ? `Editar borrador ${invoice.id}` : 'Nueva factura';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <PageHeader title={title} />
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <FormField
                        label="Cliente"
                        htmlFor="client"
                        required
                        error={errors.client_id}
                    >
                        <ClientPicker
                            value={selectedClient}
                            onPick={pickClient}
                            disabled={processing}
                        />
                    </FormField>
                    <FormField
                        label="Vendedor"
                        htmlFor="salesperson"
                        required
                        error={errors.salesperson_id}
                    >
                        <Select
                            value={
                                data.salesperson_id
                                    ? String(data.salesperson_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                setData('salesperson_id', Number(value))
                            }
                            disabled={lockSalesperson}
                        >
                            <SelectTrigger id="salesperson" className="w-full">
                                <SelectValue placeholder="Selecciona el vendedor" />
                            </SelectTrigger>
                            <SelectContent>
                                {salespeople.map((person) => (
                                    <SelectItem
                                        key={person.id}
                                        value={String(person.id)}
                                    >
                                        {person.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Almacén"
                        htmlFor="warehouse"
                        required
                        error={errors.warehouse_id}
                    >
                        <Select
                            value={
                                data.warehouse_id
                                    ? String(data.warehouse_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                setData('warehouse_id', Number(value))
                            }
                        >
                            <SelectTrigger id="warehouse" className="w-full">
                                <SelectValue placeholder="Selecciona el almacén" />
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
                    <FormField
                        label="Moneda"
                        htmlFor="currency"
                        required
                        error={errors.currency_id}
                    >
                        <Select
                            value={
                                data.currency_id ? String(data.currency_id) : ''
                            }
                            onValueChange={(value) =>
                                setData('currency_id', Number(value))
                            }
                        >
                            <SelectTrigger id="currency" className="w-full">
                                <SelectValue placeholder="Selecciona la moneda" />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        {item.code}
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
                        label="Condición"
                        htmlFor="payment_terms"
                        required
                        error={errors.payment_terms}
                    >
                        <Select
                            value={data.payment_terms}
                            onValueChange={setPaymentTerms}
                        >
                            <SelectTrigger
                                id="payment_terms"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {paymentTerms.map((term) => (
                                    <SelectItem
                                        key={term.value}
                                        value={term.value}
                                    >
                                        {term.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Días de crédito"
                        htmlFor="credit_days"
                        required
                        error={errors.credit_days}
                    >
                        <Input
                            id="credit_days"
                            type="number"
                            min={0}
                            max={365}
                            disabled={isCash}
                            value={isCash ? 0 : data.credit_days}
                            onChange={(event) =>
                                setData(
                                    'credit_days',
                                    Number(event.target.value),
                                )
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
                        clientId={data.client_id}
                        currency={currency}
                    />
                </FormField>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Guardar borrador
                    </Button>
                    {invoice && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={processing}
                            onClick={issue}
                        >
                            Emitir factura
                        </Button>
                    )}
                    <Button type="button" variant="outline" asChild>
                        <Link href={invoice ? show(invoice.id) : index()}>
                            Cancelar
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

InvoiceForm.layout = {
    breadcrumbs: [{ title: 'Facturas', href: index() }],
};
