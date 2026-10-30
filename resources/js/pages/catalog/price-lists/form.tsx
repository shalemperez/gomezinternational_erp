import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    create,
    edit,
    index,
    store,
    update,
} from '@/routes/catalog/price-lists';
import type { AuditEntry } from '@/types/audit';
import type { PriceList } from '@/types/catalog';

type Props = {
    priceList?: PriceList;
    history?: AuditEntry[];
    currencies: { id: number; code: string; name: string }[];
};

export default function PriceListForm({
    priceList,
    history,
    currencies,
}: Props) {
    const title = priceList
        ? 'Editar lista de precios'
        : 'Nueva lista de precios';
    const form = (
        <Form
            {...(priceList ? update.form(priceList.id) : store.form())}
            className="grid max-w-2xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <FormField
                        label="Nombre"
                        htmlFor="name"
                        error={errors.name}
                        required
                    >
                        <Input
                            id="name"
                            name="name"
                            maxLength={100}
                            defaultValue={priceList?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Moneda"
                        htmlFor="currency_id"
                        error={errors.currency_id}
                        required
                    >
                        <Select
                            name="currency_id"
                            defaultValue={
                                priceList
                                    ? String(priceList.currency_id)
                                    : undefined
                            }
                            required
                        >
                            <SelectTrigger id="currency_id">
                                <SelectValue placeholder="Seleccione una moneda" />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((currency) => (
                                    <SelectItem
                                        key={currency.id}
                                        value={String(currency.id)}
                                    >
                                        {`${currency.code} — ${currency.name}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Lista predeterminada"
                        htmlFor="is_default"
                        error={errors.is_default}
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_default"
                            name="is_default"
                            defaultChecked={priceList?.is_default ?? false}
                        />
                    </FormField>
                    <FormField
                        label="Activo"
                        htmlFor="is_active"
                        error={errors.is_active}
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_active"
                            name="is_active"
                            defaultChecked={priceList?.is_active ?? true}
                        />
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

PriceListForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Listas de precios', href: index() },
        {
            title: props.priceList
                ? 'Editar lista de precios'
                : 'Nueva lista de precios',
            href: props.priceList ? edit.url(props.priceList.id) : create.url(),
        },
    ],
});
