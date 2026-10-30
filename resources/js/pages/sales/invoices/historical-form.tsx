import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { ClientPicker } from '@/components/erp/client-picker';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { index } from '@/routes/sales/invoices';
import { store } from '@/routes/sales/invoices/historical';
import type { PickedClient } from '@/types/sales';

type Props = {
    currencies: { id: number; code: string }[];
    salespeople: { id: number; name: string }[];
    defaultCurrencyId: number;
};

type HistoricalInvoiceFormData = {
    ncf: string;
    client_id: number | null;
    salesperson_id: number | null;
    issue_date: string;
    due_date: string;
    currency_id: number | null;
    taxable_total: string;
    itbis_total: string;
    total: string;
    paid_amount: string;
    notes: string;
};

export default function HistoricalInvoiceForm({
    currencies,
    salespeople,
    defaultCurrencyId,
}: Props) {
    const { data, setData, post, processing, errors } =
        useForm<HistoricalInvoiceFormData>({
            ncf: '',
            client_id: null,
            salesperson_id: null,
            issue_date: '',
            due_date: '',
            currency_id: defaultCurrencyId,
            taxable_total: '',
            itbis_total: '',
            total: '',
            paid_amount: '0',
            notes: '',
        });

    const [selectedClient, setSelectedClient] = useState<PickedClient | null>(
        null,
    );

    const pickClient = (client: PickedClient) => {
        setSelectedClient(client);
        setData((current) => ({
            ...current,
            client_id: client.id,
            currency_id:
                currencies.find((item) => item.code === client.currency_code)
                    ?.id ?? current.currency_id,
        }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(store.url());
    };

    const title = 'Registrar factura histórica';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <PageHeader title={title} />
                <Alert>
                    <AlertDescription>
                        Use esta pantalla solo para facturas emitidas antes de
                        usar el sistema. No se reportan en el 607 ni se
                        reemiten.
                    </AlertDescription>
                </Alert>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <FormField
                        label="NCF"
                        htmlFor="ncf"
                        required
                        error={errors.ncf}
                    >
                        <Input
                            id="ncf"
                            value={data.ncf}
                            onChange={(event) =>
                                setData('ncf', event.target.value.toUpperCase())
                            }
                        />
                    </FormField>
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
                        >
                            <SelectTrigger id="salesperson" className="w-full">
                                <SelectValue placeholder="Vendedor del cliente" />
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
                        label="Vencimiento"
                        htmlFor="due_date"
                        error={errors.due_date}
                    >
                        <Input
                            id="due_date"
                            type="date"
                            value={data.due_date}
                            onChange={(event) =>
                                setData('due_date', event.target.value)
                            }
                        />
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
                        label="Base imponible"
                        htmlFor="taxable_total"
                        required
                        error={errors.taxable_total}
                    >
                        <Input
                            id="taxable_total"
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.taxable_total}
                            onChange={(event) =>
                                setData('taxable_total', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="ITBIS"
                        htmlFor="itbis_total"
                        required
                        error={errors.itbis_total}
                    >
                        <Input
                            id="itbis_total"
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.itbis_total}
                            onChange={(event) =>
                                setData('itbis_total', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Total"
                        htmlFor="total"
                        required
                        error={errors.total}
                    >
                        <Input
                            id="total"
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.total}
                            onChange={(event) =>
                                setData('total', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Pagado"
                        htmlFor="paid_amount"
                        error={errors.paid_amount}
                    >
                        <Input
                            id="paid_amount"
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.paid_amount}
                            onChange={(event) =>
                                setData('paid_amount', event.target.value)
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

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Registrar
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link href={index()}>Cancelar</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

HistoricalInvoiceForm.layout = {
    breadcrumbs: [{ title: 'Facturas', href: index() }],
};
