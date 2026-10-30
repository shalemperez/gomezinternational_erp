import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
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
} from '@/routes/admin/ncf-sequences';
import type { AuditEntry } from '@/types/audit';
import type { NcfSequenceRow } from '@/types/fiscal';

type Props = {
    ncfSequence?: Pick<
        NcfSequenceRow,
        | 'id'
        | 'ncf_type'
        | 'range_from'
        | 'range_to'
        | 'next_number'
        | 'expires_on'
        | 'authorization_number'
        | 'is_active'
        | 'notes'
    >;
    history?: AuditEntry[];
    types: { value: string; label: string; expires: boolean }[];
};

export default function NcfSequenceForm({
    ncfSequence,
    history,
    types,
}: Props) {
    const title = ncfSequence ? 'Editar secuencia' : 'Nueva secuencia';
    const [ncfType, setNcfType] = useState<string>(
        ncfSequence?.ncf_type ?? types[0]?.value ?? 'B01',
    );
    const isUsed =
        ncfSequence !== undefined &&
        ncfSequence.next_number > ncfSequence.range_from;
    const expires =
        types.find((option) => option.value === ncfType)?.expires ?? true;

    const form = (
        <Form
            {...(ncfSequence ? update.form(ncfSequence.id) : store.form())}
            className="grid max-w-2xl gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <FormField
                        label="Tipo"
                        htmlFor="ncf_type"
                        error={errors.ncf_type}
                        required
                    >
                        <Select
                            value={ncfType}
                            onValueChange={setNcfType}
                            disabled={isUsed}
                        >
                            <SelectTrigger id="ncf_type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {`${option.value} — ${option.label}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <input type="hidden" name="ncf_type" value={ncfType} />
                    </FormField>
                    <FormField
                        label="Desde"
                        htmlFor="range_from"
                        error={errors.range_from}
                        required
                    >
                        <Input
                            id="range_from"
                            name="range_from"
                            type="number"
                            min={1}
                            max={99999999}
                            defaultValue={ncfSequence?.range_from}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Hasta"
                        htmlFor="range_to"
                        error={errors.range_to}
                        required
                    >
                        <Input
                            id="range_to"
                            name="range_to"
                            type="number"
                            min={1}
                            max={99999999}
                            defaultValue={ncfSequence?.range_to}
                            required
                        />
                    </FormField>
                    <FormField
                        label="Vence"
                        htmlFor="expires_on"
                        error={errors.expires_on}
                        required={expires}
                        hint="Obligatorio salvo Consumo (B02) y Notas de Crédito (B04)"
                    >
                        <Input
                            id="expires_on"
                            name="expires_on"
                            type="date"
                            defaultValue={ncfSequence?.expires_on ?? ''}
                            required={expires}
                        />
                    </FormField>
                    <FormField
                        label="Autorización"
                        htmlFor="authorization_number"
                        error={errors.authorization_number}
                    >
                        <Input
                            id="authorization_number"
                            name="authorization_number"
                            maxLength={50}
                            defaultValue={
                                ncfSequence?.authorization_number ?? ''
                            }
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
                            maxLength={255}
                            defaultValue={ncfSequence?.notes ?? ''}
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
                            defaultChecked={ncfSequence?.is_active ?? true}
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

NcfSequenceForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Secuencias NCF', href: index() },
        {
            title: props.ncfSequence ? 'Editar secuencia' : 'Nueva secuencia',
            href: props.ncfSequence
                ? edit.url(props.ncfSequence.id)
                : create.url(),
        },
    ],
});
