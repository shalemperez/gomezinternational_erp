import { Form, Head, Link, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    type Column,
    type Paginated,
} from '@/components/erp/data-table';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/lib/can';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { fetch, index, store, update } from '@/routes/finance/exchange-rates';
import { index as currenciesIndex } from '@/routes/finance/currencies';
import type { CurrencyWithLatest, ExchangeRateRow } from '@/types/finance';

type Props = {
    currencies: CurrencyWithLatest[];
    rows: Paginated<ExchangeRateRow>;
    filters: Record<string, string | undefined>;
    sourceType: 'manual' | 'http_json';
    lastFetchError: { at: string; message: string } | null;
    today: string;
    canManage: boolean;
};

export default function ExchangeRatesIndex({
    currencies,
    rows,
    filters,
    sourceType,
    lastFetchError,
    today,
    canManage,
}: Props) {
    const can = useCan();
    const [editing, setEditing] = useState<ExchangeRateRow | null>(null);

    const columns: Column<ExchangeRateRow>[] = [
        {
            key: 'rate_date',
            label: 'Fecha',
            sortable: true,
            render: (row) => formatDate(row.rate_date),
        },
        {
            key: 'currency',
            label: 'Moneda',
            render: (row) => row.currency.code,
        },
        {
            key: 'rate',
            label: 'Tasa',
            sortable: true,
            render: (row) => formatNumber(row.rate, 6),
        },
        { key: 'source', label: 'Fuente', render: (row) => row.source_label },
        {
            key: 'creator',
            label: 'Registrada por',
            render: (row) => row.creator?.name ?? 'Sistema',
        },
        { key: 'notes', label: 'Notas', render: (row) => row.notes ?? '' },
    ];

    return (
        <>
            <Head title="Monedas y tasas de cambio" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Monedas y tasas de cambio"
                    actions={
                        can('finance.currencies.manage') && (
                            <Button variant="outline" asChild>
                                <Link href={currenciesIndex()}>Monedas</Link>
                            </Button>
                        )
                    }
                />

                {lastFetchError && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            La última obtención automática falló el{' '}
                            {formatDateTime(lastFetchError.at)}:{' '}
                            {lastFetchError.message}. Registra la tasa
                            manualmente.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {currencies.map((currency) => (
                        <Card key={currency.id}>
                            <CardHeader>
                                <CardTitle>
                                    {currency.code} — {currency.name}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {currency.latest_rate
                                    ? `1 ${currency.code} = RD$ ${formatNumber(currency.latest_rate.rate, 4)} (${formatDate(currency.latest_rate.rate_date)}, ${currency.latest_rate.source})`
                                    : 'Sin tasa registrada'}
                            </CardContent>
                        </Card>
                    ))}

                    {canManage && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Registrar tasa</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Form {...store.form()} className="grid gap-4">
                                    {({ errors, processing }) => (
                                        <>
                                            <FormField
                                                label="Moneda"
                                                htmlFor="currency_id"
                                                error={errors.currency_id}
                                                required
                                            >
                                                <Select name="currency_id">
                                                    <SelectTrigger id="currency_id">
                                                        <SelectValue placeholder="Selecciona una moneda" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {currencies.map(
                                                            (currency) => (
                                                                <SelectItem
                                                                    key={
                                                                        currency.id
                                                                    }
                                                                    value={String(
                                                                        currency.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        currency.code
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </FormField>
                                            <FormField
                                                label="Fecha"
                                                htmlFor="rate_date"
                                                error={errors.rate_date}
                                                required
                                            >
                                                <Input
                                                    id="rate_date"
                                                    type="date"
                                                    name="rate_date"
                                                    defaultValue={today}
                                                    required
                                                />
                                            </FormField>
                                            <FormField
                                                label="Tasa (RD$ por 1 unidad)"
                                                htmlFor="rate"
                                                error={errors.rate}
                                                required
                                            >
                                                <Input
                                                    id="rate"
                                                    name="rate"
                                                    inputMode="decimal"
                                                    required
                                                />
                                            </FormField>
                                            <FormField
                                                label="Notas"
                                                htmlFor="notes"
                                                error={errors.notes}
                                            >
                                                <Input
                                                    id="notes"
                                                    name="notes"
                                                />
                                            </FormField>
                                            <p className="text-sm text-muted-foreground">
                                                Fuente configurada:{' '}
                                                {sourceType === 'manual'
                                                    ? 'Manual'
                                                    : 'Servicio externo (HTTP JSON)'}{' '}
                                                — se cambia en Configuración.
                                            </p>
                                            {sourceType === 'http_json' && (
                                                <Button
                                                    variant="outline"
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(fetch.url())
                                                    }
                                                >
                                                    Obtener ahora desde la
                                                    fuente externa
                                                </Button>
                                            )}
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Guardar
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <DataTable
                    columns={columns}
                    rows={rows}
                    routeUrl={index.url()}
                    filters={filters}
                    searchPlaceholder="Buscar…"
                    toolbar={
                        <Select
                            value={filters.currency_id ?? 'all'}
                            onValueChange={(value) =>
                                router.get(
                                    index.url(),
                                    {
                                        ...filters,
                                        currency_id:
                                            value === 'all' ? undefined : value,
                                        page: undefined,
                                    },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        replace: true,
                                    },
                                )
                            }
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Todas las monedas" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Todas las monedas
                                </SelectItem>
                                {currencies.map((currency) => (
                                    <SelectItem
                                        key={currency.id}
                                        value={String(currency.id)}
                                    >
                                        {currency.code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    rowActions={(row) =>
                        canManage && (
                            <Dialog
                                open={editing?.id === row.id}
                                onOpenChange={(open) =>
                                    setEditing(open ? row : null)
                                }
                            >
                                <DialogTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <Pencil /> Corregir
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Corregir tasa de {row.currency.code}
                                        </DialogTitle>
                                    </DialogHeader>
                                    <Form
                                        {...update.form(row.id)}
                                        className="grid gap-4"
                                        onSuccess={() => setEditing(null)}
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <FormField
                                                    label="Tasa (RD$ por 1 unidad)"
                                                    htmlFor={`rate-${row.id}`}
                                                    error={errors.rate}
                                                    required
                                                >
                                                    <Input
                                                        id={`rate-${row.id}`}
                                                        name="rate"
                                                        inputMode="decimal"
                                                        defaultValue={row.rate}
                                                        required
                                                    />
                                                </FormField>
                                                <FormField
                                                    label="Notas"
                                                    htmlFor={`notes-${row.id}`}
                                                    error={errors.notes}
                                                >
                                                    <Input
                                                        id={`notes-${row.id}`}
                                                        name="notes"
                                                        defaultValue={
                                                            row.notes ?? ''
                                                        }
                                                    />
                                                </FormField>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Guardar
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )
                    }
                />
            </div>
        </>
    );
}

ExchangeRatesIndex.layout = {
    breadcrumbs: [{ title: 'Monedas y tasas', href: index() }],
};
