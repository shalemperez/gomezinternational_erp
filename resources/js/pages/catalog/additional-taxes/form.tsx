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
} from '@/routes/catalog/additional-taxes';
import type { AuditEntry } from '@/types/audit';
import type { AdditionalTax } from '@/types/catalog';

type Props = {
    additionalTax?: AdditionalTax;
    history?: AuditEntry[];
    calculationTypes: { value: string; label: string }[];
};

export default function AdditionalTaxForm({
    additionalTax,
    history,
    calculationTypes,
}: Props) {
    const title = additionalTax
        ? 'Editar impuesto adicional'
        : 'Nuevo impuesto adicional';
    const form = (
        <Form
            {...(additionalTax ? update.form(additionalTax.id) : store.form())}
            className="grid max-w-2xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <FormField
                        label="Código"
                        htmlFor="code"
                        error={errors.code}
                        required
                    >
                        <Input
                            id="code"
                            name="code"
                            maxLength={20}
                            className="uppercase"
                            defaultValue={additionalTax?.code}
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
                            defaultValue={additionalTax?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Tipo de cálculo"
                        htmlFor="calculation_type"
                        error={errors.calculation_type}
                        required
                    >
                        <Select
                            name="calculation_type"
                            defaultValue={
                                additionalTax?.calculation_type ??
                                'percent_of_base'
                            }
                        >
                            <SelectTrigger id="calculation_type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {calculationTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Tasa (%)"
                        htmlFor="rate"
                        error={errors.rate}
                    >
                        <Input
                            id="rate"
                            name="rate"
                            inputMode="decimal"
                            defaultValue={additionalTax?.rate ?? ''}
                        />
                    </FormField>
                    <FormField
                        label="Monto"
                        htmlFor="amount"
                        error={errors.amount}
                    >
                        <Input
                            id="amount"
                            name="amount"
                            inputMode="decimal"
                            defaultValue={additionalTax?.amount ?? ''}
                        />
                    </FormField>
                    <FormField
                        label="Incluido en la base del ITBIS"
                        htmlFor="included_in_itbis_base"
                        error={errors.included_in_itbis_base}
                        hint="Marcado: el ITBIS se calcula sobre base + este impuesto (caso normal del ISC)."
                    >
                        <Switch
                            id="included_in_itbis_base"
                            name="included_in_itbis_base"
                            defaultChecked={
                                additionalTax?.included_in_itbis_base ?? true
                            }
                        />
                    </FormField>
                    <FormField
                        label="Código DGII"
                        htmlFor="dgii_code"
                        error={errors.dgii_code}
                        hint="Código del tipo de impuesto adicional según la DGII; se usa en el e-CF (fase 3). Déjalo vacío si no lo conoces."
                    >
                        <Input
                            id="dgii_code"
                            name="dgii_code"
                            maxLength={3}
                            defaultValue={additionalTax?.dgii_code ?? ''}
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
                            defaultChecked={additionalTax?.is_active ?? true}
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

AdditionalTaxForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Impuestos adicionales', href: index() },
        {
            title: props.additionalTax
                ? 'Editar impuesto adicional'
                : 'Nuevo impuesto adicional',
            href: props.additionalTax
                ? edit.url(props.additionalTax.id)
                : create.url(),
        },
    ],
});
