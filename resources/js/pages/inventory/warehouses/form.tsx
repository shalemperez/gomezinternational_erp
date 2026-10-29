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
} from '@/routes/inventory/warehouses';
import type { AuditEntry } from '@/types/audit';
import type { Warehouse } from '@/types/inventory';

type Props = {
    warehouse?: Warehouse;
    history?: AuditEntry[];
};

export default function WarehouseForm({ warehouse, history }: Props) {
    const title = warehouse ? 'Editar almacén' : 'Nuevo almacén';
    const form = (
        <Form
            {...(warehouse ? update.form(warehouse.id) : store.form())}
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
                            defaultValue={warehouse?.code}
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
                            maxLength={100}
                            defaultValue={warehouse?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Dirección"
                        htmlFor="address"
                        error={errors.address}
                    >
                        <Input
                            id="address"
                            name="address"
                            maxLength={255}
                            defaultValue={warehouse?.address ?? ''}
                        />
                    </FormField>
                    <FormField
                        label="Almacén predeterminado"
                        htmlFor="is_default"
                        error={errors.is_default}
                        className="flex items-center gap-2"
                    >
                        <Switch
                            id="is_default"
                            name="is_default"
                            defaultChecked={warehouse?.is_default ?? false}
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
                            defaultChecked={warehouse?.is_active ?? true}
                            disabled={warehouse?.is_default}
                        />
                        {warehouse?.is_default && (
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

WarehouseForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Almacenes', href: index() },
        {
            title: props.warehouse ? 'Editar almacén' : 'Nuevo almacén',
            href: props.warehouse ? edit.url(props.warehouse.id) : create.url(),
        },
    ],
});
