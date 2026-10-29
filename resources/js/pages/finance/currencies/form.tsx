import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    create,
    edit,
    index,
    store,
    update,
} from '@/routes/finance/currencies';
import type { AuditEntry } from '@/types/audit';
import type { Currency } from '@/types/finance';

type Props = {
    currency?: Currency;
    history?: AuditEntry[];
};

export default function CurrencyForm({ currency, history }: Props) {
    const title = currency ? 'Editar moneda' : 'Nueva moneda';
    const form = (
        <Form
            {...(currency ? update.form(currency.id) : store.form())}
            className="grid max-w-2xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <FormField
                        label="Código"
                        htmlFor="code"
                        error={errors.code}
                        required
                        hint="Código ISO de 3 letras; no se puede cambiar"
                    >
                        <Input
                            id="code"
                            name="code"
                            maxLength={3}
                            className="uppercase"
                            defaultValue={currency?.code}
                            disabled={!!currency}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Nombre"
                        htmlFor="name"
                        error={errors.name}
                        required
                    >
                        <Input
                            id="name"
                            name="name"
                            defaultValue={currency?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Símbolo"
                        htmlFor="symbol"
                        error={errors.symbol}
                        required
                    >
                        <Input
                            id="symbol"
                            name="symbol"
                            defaultValue={currency?.symbol}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Decimales"
                        htmlFor="decimal_places"
                        error={errors.decimal_places}
                        required
                    >
                        <Input
                            id="decimal_places"
                            name="decimal_places"
                            type="number"
                            min={0}
                            max={4}
                            defaultValue={currency?.decimal_places ?? 2}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Activa"
                        htmlFor="is_active"
                        error={errors.is_active}
                        hint={
                            currency?.is_base
                                ? 'La moneda base siempre está activa'
                                : undefined
                        }
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_active"
                            name="is_active"
                            defaultChecked={currency?.is_active ?? true}
                            disabled={currency?.is_base}
                        />
                        {currency?.is_base && (
                            <input type="hidden" name="is_active" value="on" />
                        )}
                    </FormField>
                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );

    return (
        <>
            <Head title={title} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title={title} />
                {history ? (
                    <Tabs defaultValue="datos">
                        <TabsList>
                            <TabsTrigger value="datos">Datos</TabsTrigger>
                            <TabsTrigger value="historial">
                                Historial
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="datos">{form}</TabsContent>
                        <TabsContent value="historial">
                            <RecordHistory history={history} />
                        </TabsContent>
                    </Tabs>
                ) : (
                    form
                )}
            </div>
        </>
    );
}

CurrencyForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Monedas', href: index() },
        {
            title: props.currency ? 'Editar moneda' : 'Nueva moneda',
            href: props.currency ? edit.url(props.currency.id) : create.url(),
        },
    ],
});
