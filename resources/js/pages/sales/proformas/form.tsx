import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
import { create, index, store, update } from '@/routes/sales/proformas';
import type { DocumentLineInput, PickedClient, Proforma } from '@/types/sales';

type Props = {
    proforma?: Proforma;
    currencies: { id: number; code: string }[];
    salespeople: { id: number; name: string }[];
    lockSalesperson: boolean;
    defaultCurrencyId: number;
    validityDays: number;
    today: string;
};

type ProformaFormData = {
    client_id: number | null;
    salesperson_id: number | null;
    currency_id: number | null;
    issue_date: string;
    valid_until: string;
    price_list_id: number | null;
    notes: string;
    lines: DocumentLineInput[];
};

function addDays(isoDate: string, days: number): string {
    const date = new Date(`${isoDate}T00:00:00`);
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

export default function ProformaForm({
    proforma,
    currencies,
    salespeople,
    lockSalesperson,
    defaultCurrencyId,
    validityDays,
    today,
}: Props) {
    const { data, setData, transform, post, put, processing, errors } =
        useForm<ProformaFormData>({
            client_id: proforma?.client_id ?? null,
            salesperson_id:
                proforma?.salesperson_id ??
                (lockSalesperson ? (salespeople[0]?.id ?? null) : null),
            currency_id: proforma?.currency_id ?? defaultCurrencyId,
            issue_date: proforma?.issue_date ?? today,
            valid_until: proforma?.valid_until ?? addDays(today, validityDays),
            price_list_id: proforma?.price_list_id ?? null,
            notes: proforma?.notes ?? '',
            lines: (proforma?.lines ?? []).map((line) => ({
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

    const pickClient = (client: PickedClient) => {
        setData((current) => ({
            ...current,
            client_id: client.id,
            currency_id:
                currencies.find((item) => item.code === client.currency_code)
                    ?.id ?? current.currency_id,
            price_list_id: client.price_list_id,
        }));
    };

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
        if (proforma) {
            put(update.url(proforma.id));
        } else {
            post(store.url());
        }
    };

    const selectedClient: PickedClient | null = proforma?.client
        ? ({
              id: proforma.client.id,
              business_name: proforma.client.business_name,
              formatted_tax_id: proforma.client.formatted_tax_id,
          } as PickedClient)
        : null;

    const title = proforma ? `Editar ${proforma.number}` : 'Nueva proforma';

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
                        label="Válida hasta"
                        htmlFor="valid_until"
                        required
                        error={errors.valid_until}
                    >
                        <Input
                            id="valid_until"
                            type="date"
                            value={data.valid_until}
                            onChange={(event) =>
                                setData('valid_until', event.target.value)
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
                        Guardar proforma
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link href={proforma ? index() : create()}>
                            Cancelar
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

ProformaForm.layout = {
    breadcrumbs: [{ title: 'Proformas', href: index() }],
};
