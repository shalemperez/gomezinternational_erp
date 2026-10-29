import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { create, edit, index, store, update } from '@/routes/catalog/units';
import type { AuditEntry } from '@/types/audit';
import type { UnitOfMeasure } from '@/types/catalog';

type Props = {
    unit?: UnitOfMeasure;
    history?: AuditEntry[];
};

export default function UnitOfMeasureForm({ unit, history }: Props) {
    const title = unit ? 'Editar unidad' : 'Nueva unidad';
    const form = (
        <Form
            {...(unit ? update.form(unit.id) : store.form())}
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
                            maxLength={10}
                            className="uppercase"
                            defaultValue={unit?.code}
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
                            defaultValue={unit?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Activa"
                        htmlFor="is_active"
                        error={errors.is_active}
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_active"
                            name="is_active"
                            defaultChecked={unit?.is_active ?? true}
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

UnitOfMeasureForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Unidades de medida', href: index() },
        {
            title: props.unit ? 'Editar unidad' : 'Nueva unidad',
            href: props.unit ? edit.url(props.unit.id) : create.url(),
        },
    ],
});
