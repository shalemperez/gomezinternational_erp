import { Form, Head, Link } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { create, edit, index, store, update } from '@/routes/admin/users';
import type { AdminUserForm, RoleOption } from '@/types/admin';
import type { AuditEntry } from '@/types/audit';

type Props = {
    user?: AdminUserForm;
    history?: AuditEntry[];
    roleOptions: RoleOption[];
    authId: number;
};

export default function AdminUserForm({
    user,
    history,
    roleOptions,
    authId,
}: Props) {
    const title = user ? 'Editar usuario' : 'Invitar usuario';
    const form = (
        <Form
            {...(user ? update.form(user.id) : store.form())}
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
                            defaultValue={user?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Correo"
                        htmlFor="email"
                        error={errors.email}
                        required
                    >
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            defaultValue={user?.email}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Roles"
                        htmlFor="roles"
                        error={errors['roles.0'] ?? errors.roles}
                        required
                    >
                        <div className="grid gap-2" id="roles">
                            {roleOptions.map((option) => (
                                <div
                                    key={option.name}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`role-${option.name}`}
                                        name="roles[]"
                                        value={option.name}
                                        defaultChecked={user?.roles.includes(
                                            option.name,
                                        )}
                                    />
                                    <Label htmlFor={`role-${option.name}`}>
                                        {option.label}
                                    </Label>
                                </div>
                            ))}
                        </div>
                    </FormField>
                    {user && (
                        <FormField
                            label="Activo"
                            htmlFor="is_active"
                            error={errors.is_active}
                            hint="Un usuario desactivado no puede iniciar sesión"
                            className="flex items-center gap-2"
                        >
                            <Switch
                                id="is_active"
                                name="is_active"
                                defaultChecked={user.is_active}
                                disabled={user.id === authId}
                            />
                            {user.id === authId && (
                                <input
                                    type="hidden"
                                    name="is_active"
                                    value="on"
                                />
                            )}
                        </FormField>
                    )}
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

AdminUserForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Usuarios', href: index() },
        {
            title: props.user ? 'Editar usuario' : 'Invitar usuario',
            href: props.user ? edit.url(props.user.id) : create.url(),
        },
    ],
});
