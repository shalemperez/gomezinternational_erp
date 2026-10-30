import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';
import { ClientPicker } from '@/components/erp/client-picker';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { formatDate, formatMoney } from '@/lib/format';
import { useReceiptPreview } from '@/hooks/use-receipt-preview';
import { create, index, store } from '@/routes/sales/receipts';
import type { OpenInvoiceRow, PickedClient } from '@/types/sales';

type Props = {
    client: PickedClient | null;
    openInvoices: OpenInvoiceRow[];
    advances: {
        id: number;
        number: string;
        unapplied_amount: string;
        currency_id: number;
    }[];
    accounts: { id: number; name: string; type: string; currency_id: number }[];
    paymentMethods: { value: string; label: string }[];
    currencies: { id: number; code: string; name: string; is_base: boolean }[];
    advanceTotals: Record<string, string>;
};

type ApplicationRow = {
    invoice_id: number;
    amount: string;
    selected: boolean;
};

type ReceiptFormData = {
    client_id: number | null;
    currency_id: number | null;
    receipt_date: string;
    payment_method: string;
    financial_account_id: number | null;
    reference: string;
    amount_received: string;
    retention_itbis: string;
    retention_isr: string;
    notes: string;
    applications: ApplicationRow[];
};

const applicationRowsFor = (
    openInvoices: OpenInvoiceRow[],
    preselectedInvoiceId: number | null,
): ApplicationRow[] =>
    openInvoices.map((invoice) => ({
        invoice_id: invoice.id,
        amount: invoice.balance,
        selected: invoice.id === preselectedInvoiceId,
    }));

export default function ReceiptForm({
    client,
    openInvoices,
    accounts,
    paymentMethods,
    currencies,
    advanceTotals,
}: Props) {
    const { url } = usePage();
    const preselectedInvoiceId = Number(
        new URL(url, 'http://localhost').searchParams.get('invoice') ?? 0,
    );
    const baseCurrency = currencies.find((currency) => currency.is_base);

    const [selectedClient, setSelectedClient] = useState<PickedClient | null>(
        client,
    );

    const { data, setData, transform, post, processing, errors } =
        useForm<ReceiptFormData>({
            client_id: client?.id ?? null,
            currency_id:
                currencies.find(
                    (currency) => currency.code === client?.currency_code,
                )?.id ??
                baseCurrency?.id ??
                null,
            receipt_date: new Date().toISOString().slice(0, 10),
            payment_method: 'transfer',
            financial_account_id: null,
            reference: '',
            amount_received: '0',
            retention_itbis: '0',
            retention_isr: '0',
            notes: '',
            applications: applicationRowsFor(
                openInvoices,
                preselectedInvoiceId || null,
            ),
        });

    useEffect(() => {
        setData(
            'applications',
            applicationRowsFor(openInvoices, preselectedInvoiceId || null),
        );
        // Re-seed only when the client's open invoices change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [openInvoices]);

    const currencyCode =
        currencies.find((currency) => currency.id === data.currency_id)?.code ??
        'DOP';
    const currency = currencyCode === 'USD' ? 'USD' : 'DOP';
    const currencyAccounts = accounts.filter(
        (account) => account.currency_id === data.currency_id,
    );
    const currencyInvoices = openInvoices.filter(
        (invoice) => invoice.currency_id === data.currency_id,
    );
    const currencyInvoiceIds = new Set(
        currencyInvoices.map((invoice) => invoice.id),
    );
    const advanceTotal =
        advanceTotals[String(data.currency_id ?? '')] ?? '0.00';

    const applicationByInvoice = new Map(
        data.applications.map((application) => [
            application.invoice_id,
            application,
        ]),
    );
    const selectedApplications = data.applications.filter(
        (application) =>
            application.selected &&
            currencyInvoiceIds.has(application.invoice_id),
    );
    const summary = useReceiptPreview({
        amount_received: data.amount_received,
        retention_itbis: data.retention_itbis,
        retention_isr: data.retention_isr,
        applications: selectedApplications.map(({ invoice_id, amount }) => ({
            invoice_id,
            amount,
        })),
    });

    const updateApplication = (
        invoiceId: number,
        patch: Partial<ApplicationRow>,
    ) =>
        setData(
            'applications',
            data.applications.map((application) =>
                application.invoice_id === invoiceId
                    ? { ...application, ...patch }
                    : application,
            ),
        );

    const pickClient = (picked: PickedClient) => {
        setSelectedClient(picked);
        setData((current) => ({
            ...current,
            client_id: picked.id,
            currency_id:
                currencies.find((item) => item.code === picked.currency_code)
                    ?.id ?? current.currency_id,
            financial_account_id: null,
        }));
        router.get(
            create.url({ query: { client: picked.id } }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['openInvoices', 'client', 'advances'],
            },
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        transform((current) => ({
            ...current,
            applications: current.applications
                .filter(
                    (application) =>
                        application.selected &&
                        currencyInvoiceIds.has(application.invoice_id),
                )
                .map(({ invoice_id, amount }) => ({ invoice_id, amount })),
        }));
        post(store.url());
    };

    return (
        <>
            <Head title="Nuevo cobro" />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <PageHeader title="Nuevo cobro" />
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
                                setData((current) => ({
                                    ...current,
                                    currency_id: Number(value),
                                    financial_account_id: null,
                                }))
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
                        htmlFor="receipt_date"
                        required
                        error={errors.receipt_date}
                    >
                        <Input
                            id="receipt_date"
                            type="date"
                            value={data.receipt_date}
                            onChange={(event) =>
                                setData('receipt_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Método"
                        htmlFor="payment_method"
                        required
                        error={errors.payment_method}
                    >
                        <Select
                            value={data.payment_method}
                            onValueChange={(value) =>
                                setData('payment_method', value)
                            }
                        >
                            <SelectTrigger
                                id="payment_method"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {paymentMethods.map((method) => (
                                    <SelectItem
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Cuenta"
                        htmlFor="financial_account"
                        required
                        error={errors.financial_account_id}
                    >
                        <Select
                            value={
                                data.financial_account_id
                                    ? String(data.financial_account_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                setData('financial_account_id', Number(value))
                            }
                        >
                            <SelectTrigger
                                id="financial_account"
                                className="w-full"
                            >
                                <SelectValue placeholder="Selecciona la cuenta" />
                            </SelectTrigger>
                            <SelectContent>
                                {currencyAccounts.map((account) => (
                                    <SelectItem
                                        key={account.id}
                                        value={String(account.id)}
                                    >
                                        {account.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Referencia"
                        htmlFor="reference"
                        error={errors.reference}
                    >
                        <Input
                            id="reference"
                            maxLength={100}
                            value={data.reference}
                            onChange={(event) =>
                                setData('reference', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Monto recibido"
                        htmlFor="amount_received"
                        required
                        error={errors.amount_received}
                    >
                        <Input
                            id="amount_received"
                            type="number"
                            min={0}
                            step="0.01"
                            max={999999999999.99}
                            value={data.amount_received}
                            onChange={(event) =>
                                setData('amount_received', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Retención ITBIS"
                        htmlFor="retention_itbis"
                        required
                        error={errors.retention_itbis}
                    >
                        <Input
                            id="retention_itbis"
                            type="number"
                            min={0}
                            step="0.01"
                            max={999999999999.99}
                            value={data.retention_itbis}
                            onChange={(event) =>
                                setData('retention_itbis', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Retención ISR"
                        htmlFor="retention_isr"
                        required
                        error={errors.retention_isr}
                    >
                        <Input
                            id="retention_isr"
                            type="number"
                            min={0}
                            step="0.01"
                            max={999999999999.99}
                            value={data.retention_isr}
                            onChange={(event) =>
                                setData('retention_isr', event.target.value)
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

                {advanceTotal !== '0.00' && (
                    <Alert>
                        <AlertDescription>
                            Este cliente tiene saldo a favor por{' '}
                            {formatMoney(advanceTotal, currency)} — aplícalo
                            desde el recibo o la nota de crédito
                            correspondiente.
                        </AlertDescription>
                    </Alert>
                )}

                <FormField
                    label="Facturas pendientes"
                    htmlFor="applications"
                    error={errors.applications}
                >
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10" />
                                    <TableHead>Factura</TableHead>
                                    <TableHead>NCF</TableHead>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead className="text-right">
                                        Saldo
                                    </TableHead>
                                    <TableHead>Monto a aplicar</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {currencyInvoices.map((invoice) => {
                                    const application =
                                        applicationByInvoice.get(invoice.id);

                                    return (
                                        <TableRow key={invoice.id}>
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={`Aplicar a ${invoice.number}`}
                                                    checked={
                                                        application?.selected ??
                                                        false
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        updateApplication(
                                                            invoice.id,
                                                            {
                                                                selected:
                                                                    checked ===
                                                                    true,
                                                            },
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {invoice.number}
                                            </TableCell>
                                            <TableCell className="font-mono">
                                                {invoice.ncf ?? ''}
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(invoice.issue_date)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(
                                                    invoice.balance,
                                                    currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    max={999999999999.99}
                                                    aria-label={`Monto a aplicar a ${invoice.number}`}
                                                    value={
                                                        application?.amount ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        updateApplication(
                                                            invoice.id,
                                                            {
                                                                amount: event
                                                                    .target
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {currencyInvoices.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="text-center text-muted-foreground"
                                        >
                                            {selectedClient
                                                ? 'Sin facturas pendientes en esta moneda'
                                                : 'Selecciona un cliente'}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </FormField>

                <dl className="ml-auto grid w-full max-w-sm grid-cols-2 gap-x-4 text-sm">
                    <dt className="text-muted-foreground">Σ aplicado</dt>
                    <dd className="text-right">
                        {summary
                            ? formatMoney(summary.applied_total, currency)
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">Saldo a favor</dt>
                    <dd className="text-right">
                        {summary
                            ? formatMoney(summary.unapplied_amount, currency)
                            : '—'}
                    </dd>
                </dl>
                <p className="text-xs text-muted-foreground">
                    El sistema validará los montos al guardar.
                </p>

                <div className="flex gap-2">
                    <Button
                        type="submit"
                        disabled={processing || !data.client_id}
                    >
                        Registrar recibo
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link href={index()}>Cancelar</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

ReceiptForm.layout = {
    breadcrumbs: [
        { title: 'Cobros', href: index() },
        { title: 'Nuevo cobro', href: create() },
    ],
};
