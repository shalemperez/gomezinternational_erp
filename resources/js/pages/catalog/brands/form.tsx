import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { create, edit, index, store, update } from '@/routes/catalog/brands';
import type { AuditEntry } from '@/types/audit';
import type { Brand } from '@/types/catalog';

type Props = {
    brand?: Brand;
    history?: AuditEntry[];
};

export default function BrandForm({ brand, history }: Props) {
    const title = brand ? 'Editar marca' : 'Nueva marca';
    const form = (
        <Form
            {...(brand ? update.form(brand.id) : store.form())}
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
                            defaultValue={brand?.name}
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
                            defaultChecked={brand?.is_active ?? true}
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

BrandForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Marcas', href: index() },
        {
            title: props.brand ? 'Editar marca' : 'Nueva marca',
            href: props.brand ? edit.url(props.brand.id) : create.url(),
        },
    ],
});
