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
} from '@/routes/catalog/salespeople';
import type { AuditEntry } from '@/types/audit';
import type { Salesperson } from '@/types/catalog';

type Props = {
    salesperson?: Salesperson;
    history?: AuditEntry[];
    users: { id: number; label: string }[];
};

export default function SalespersonForm({
    salesperson,
    history,
    users,
}: Props) {
    const title = salesperson ? 'Editar vendedor' : 'Nuevo vendedor';
    const form = (
        <Form
            {...(salesperson ? update.form(salesperson.id) : store.form())}
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
                            defaultValue={salesperson?.code}
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
                            maxLength={120}
                            defaultValue={salesperson?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Correo"
                        htmlFor="email"
                        error={errors.email}
                    >
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            defaultValue={salesperson?.email ?? ''}
                        />
                    </FormField>
                    <FormField
                        label="Teléfono"
                        htmlFor="phone"
                        error={errors.phone}
                    >
                        <Input
                            id="phone"
                            name="phone"
                            defaultValue={salesperson?.phone ?? ''}
                        />
                    </FormField>
                    <FormField
                        label="Usuario vinculado"
                        htmlFor="user_id"
                        error={errors.user_id}
                    >
                        <Select
                            name="user_id"
                            defaultValue={
                                salesperson?.user_id
                                    ? String(salesperson.user_id)
                                    : 'none'
                            }
                        >
                            <SelectTrigger id="user_id">
                                <SelectValue placeholder="Sin usuario vinculado" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Sin usuario vinculado
                                </SelectItem>
                                {users.map((user) => (
                                    <SelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
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
                            defaultChecked={salesperson?.is_active ?? true}
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

SalespersonForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Vendedores', href: index() },
        {
            title: props.salesperson ? 'Editar vendedor' : 'Nuevo vendedor',
            href: props.salesperson
                ? edit.url(props.salesperson.id)
                : create.url(),
        },
    ],
});
