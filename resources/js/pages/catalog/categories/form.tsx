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
} from '@/routes/catalog/categories';
import type { AuditEntry } from '@/types/audit';
import type { ProductCategory } from '@/types/catalog';

type Props = {
    category?: ProductCategory;
    history?: AuditEntry[];
    parents: { id: number; name: string }[];
};

export default function ProductCategoryForm({
    category,
    history,
    parents,
}: Props) {
    const title = category ? 'Editar categoría' : 'Nueva categoría';
    const form = (
        <Form
            {...(category ? update.form(category.id) : store.form())}
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
                            defaultValue={category?.name}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Categoría padre"
                        htmlFor="parent_id"
                        error={errors.parent_id}
                    >
                        <Select
                            name="parent_id"
                            defaultValue={
                                category?.parent_id
                                    ? String(category.parent_id)
                                    : 'none'
                            }
                        >
                            <SelectTrigger id="parent_id">
                                <SelectValue placeholder="Sin categoría padre" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Sin categoría padre
                                </SelectItem>
                                {parents.map((parent) => (
                                    <SelectItem
                                        key={parent.id}
                                        value={String(parent.id)}
                                    >
                                        {parent.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
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
                            defaultChecked={category?.is_active ?? true}
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

ProductCategoryForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Categorías', href: index() },
        {
            title: props.category ? 'Editar categoría' : 'Nueva categoría',
            href: props.category ? edit.url(props.category.id) : create.url(),
        },
    ],
});
