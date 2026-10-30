import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { RecordHistory } from '@/components/erp/record-history';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { create, edit, index, store, update } from '@/routes/catalog/suppliers';
import type { AuditEntry } from '@/types/audit';
import type { Supplier, SupplierContact } from '@/types/catalog';

type Props = {
    supplier?: Supplier;
    history?: AuditEntry[];
    countries: { value: string; label: string }[];
    currencies: { id: number; code: string; name: string }[];
    canOverride: boolean;
};

type SupplierFormData = {
    code: string;
    name: string;
    country_code: string;
    tax_id: string;
    foreign_tax_id: string;
    tax_id_override: boolean;
    tax_id_override_reason: string;
    address: string;
    phone: string;
    email: string;
    currency_id: string;
    credit_days: string;
    contacts: SupplierContact[];
    notes: string;
    is_active: boolean;
};

export default function SupplierForm({
    supplier,
    history,
    countries,
    currencies,
    canOverride,
}: Props) {
    const title = supplier ? 'Editar proveedor' : 'Nuevo proveedor';
    const { data, setData, post, put, processing, errors } =
        useForm<SupplierFormData>({
            code: supplier?.code ?? '',
            name: supplier?.name ?? '',
            country_code: supplier?.country_code ?? 'DO',
            tax_id: supplier?.tax_id ?? '',
            foreign_tax_id: supplier?.foreign_tax_id ?? '',
            tax_id_override: false,
            tax_id_override_reason: '',
            address: supplier?.address ?? '',
            phone: supplier?.phone ?? '',
            email: supplier?.email ?? '',
            currency_id: String(supplier?.currency_id ?? ''),
            credit_days: String(supplier?.credit_days ?? 30),
            contacts: (supplier?.contacts ?? []).map((contact) => ({
                id: contact.id ?? null,
                name: contact.name,
                position: contact.position,
                email: contact.email,
                phone: contact.phone,
                is_primary: contact.is_primary,
            })),
            notes: supplier?.notes ?? '',
            is_active: supplier?.is_active ?? true,
        });

    const isLocal = data.country_code === 'DO';

    const changeCountry = (countryCode: string) => {
        setData({
            ...data,
            country_code: countryCode,
            // A hidden RNC would be rejected by the server with no visible field to fix.
            tax_id: countryCode === 'DO' ? data.tax_id : '',
        });
    };

    const updateContact = (
        index: number,
        changes: Partial<SupplierContact>,
    ) => {
        setData(
            'contacts',
            data.contacts.map((contact, position) =>
                position === index ? { ...contact, ...changes } : contact,
            ),
        );
    };

    const addContact = () => {
        setData('contacts', [
            ...data.contacts,
            {
                id: null,
                name: '',
                position: null,
                email: null,
                phone: null,
                is_primary: false,
            },
        ]);
    };

    const removeContact = (index: number) => {
        setData(
            'contacts',
            data.contacts.filter((_, position) => position !== index),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (supplier) {
            put(update.url(supplier.id));
        } else {
            post(store.url());
        }
    };

    const form = (
        <form onSubmit={submit} className="grid max-w-4xl gap-4">
            <Card>
                <CardHeader>
                    <CardTitle>Identificación</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2">
                    <FormField
                        label="Código"
                        htmlFor="code"
                        error={errors.code}
                        hint="Se genera automáticamente si lo dejas vacío"
                    >
                        <Input
                            id="code"
                            maxLength={20}
                            className="uppercase"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
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
                            maxLength={200}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                    </FormField>
                    <FormField
                        label="País"
                        htmlFor="country_code"
                        error={errors.country_code}
                        required
                    >
                        <Select
                            value={data.country_code}
                            onValueChange={changeCountry}
                        >
                            <SelectTrigger id="country_code">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {countries.map((country) => (
                                    <SelectItem
                                        key={country.value}
                                        value={country.value}
                                    >
                                        {country.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    {isLocal ? (
                        <FormField
                            label="RNC/Cédula"
                            htmlFor="tax_id"
                            error={errors.tax_id}
                        >
                            <Input
                                id="tax_id"
                                maxLength={20}
                                value={data.tax_id}
                                onChange={(e) =>
                                    setData('tax_id', e.target.value)
                                }
                            />
                        </FormField>
                    ) : (
                        <FormField
                            label="Identificación fiscal extranjera"
                            htmlFor="foreign_tax_id"
                            error={errors.foreign_tax_id}
                        >
                            <Input
                                id="foreign_tax_id"
                                maxLength={50}
                                value={data.foreign_tax_id}
                                onChange={(e) =>
                                    setData('foreign_tax_id', e.target.value)
                                }
                            />
                        </FormField>
                    )}
                    {isLocal && canOverride && (
                        <div className="grid gap-2 md:col-span-2">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="tax_id_override"
                                    checked={data.tax_id_override}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'tax_id_override',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label htmlFor="tax_id_override">
                                    Forzar identificación fiscal (no pasa la
                                    validación)
                                </Label>
                            </div>
                            {data.tax_id_override && (
                                <FormField
                                    label="Motivo"
                                    htmlFor="tax_id_override_reason"
                                    error={errors.tax_id_override_reason}
                                    required
                                >
                                    <Textarea
                                        id="tax_id_override_reason"
                                        maxLength={255}
                                        value={data.tax_id_override_reason}
                                        onChange={(e) =>
                                            setData(
                                                'tax_id_override_reason',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </FormField>
                            )}
                        </div>
                    )}
                    {supplier?.tax_id_validation_overridden && (
                        <div className="md:col-span-2">
                            <Badge
                                variant="outline"
                                title={`${supplier.tax_id_override_reason ?? ''} — ${supplier.tax_id_override_by_name ?? ''} ${formatDateTime(supplier.tax_id_override_at)}`}
                            >
                                Identificación forzada
                            </Badge>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Contacto</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2">
                    <FormField
                        label="Dirección"
                        htmlFor="address"
                        error={errors.address}
                    >
                        <Input
                            id="address"
                            maxLength={255}
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                    </FormField>
                    <FormField
                        label="Teléfono"
                        htmlFor="phone"
                        error={errors.phone}
                    >
                        <Input
                            id="phone"
                            maxLength={30}
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                        />
                    </FormField>
                    <FormField
                        label="Correo"
                        htmlFor="email"
                        error={errors.email}
                    >
                        <Input
                            id="email"
                            type="email"
                            maxLength={255}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Comercial</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2">
                    <FormField
                        label="Moneda"
                        htmlFor="currency_id"
                        error={errors.currency_id}
                        required
                    >
                        <Select
                            value={data.currency_id}
                            onValueChange={(value) =>
                                setData('currency_id', value)
                            }
                        >
                            <SelectTrigger id="currency_id">
                                <SelectValue />
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
                        label="Días de crédito"
                        htmlFor="credit_days"
                        error={errors.credit_days}
                        required
                    >
                        <Input
                            id="credit_days"
                            type="number"
                            min={0}
                            max={365}
                            value={data.credit_days}
                            onChange={(e) =>
                                setData('credit_days', e.target.value)
                            }
                            required
                        />
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Contactos</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3">
                    {data.contacts.map((contact, index) => (
                        <div
                            key={index}
                            className="grid gap-3 rounded-md border p-3 md:grid-cols-6 md:items-end"
                        >
                            <FormField
                                label="Nombre"
                                htmlFor={`contact_${index}_name`}
                                error={
                                    errors[
                                        `contacts.${index}.name` as keyof SupplierFormData
                                    ]
                                }
                                required
                            >
                                <Input
                                    id={`contact_${index}_name`}
                                    maxLength={120}
                                    value={contact.name}
                                    onChange={(e) =>
                                        updateContact(index, {
                                            name: e.target.value,
                                        })
                                    }
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Cargo"
                                htmlFor={`contact_${index}_position`}
                            >
                                <Input
                                    id={`contact_${index}_position`}
                                    maxLength={100}
                                    value={contact.position ?? ''}
                                    onChange={(e) =>
                                        updateContact(index, {
                                            position: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Correo"
                                htmlFor={`contact_${index}_email`}
                            >
                                <Input
                                    id={`contact_${index}_email`}
                                    type="email"
                                    maxLength={255}
                                    value={contact.email ?? ''}
                                    onChange={(e) =>
                                        updateContact(index, {
                                            email: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Teléfono"
                                htmlFor={`contact_${index}_phone`}
                            >
                                <Input
                                    id={`contact_${index}_phone`}
                                    maxLength={30}
                                    value={contact.phone ?? ''}
                                    onChange={(e) =>
                                        updateContact(index, {
                                            phone: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <div className="flex items-center gap-2 pb-2">
                                <Checkbox
                                    id={`contact_${index}_primary`}
                                    checked={contact.is_primary}
                                    onCheckedChange={(checked) =>
                                        updateContact(index, {
                                            is_primary: checked === true,
                                        })
                                    }
                                />
                                <Label htmlFor={`contact_${index}_primary`}>
                                    Principal
                                </Label>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => removeContact(index)}
                                >
                                    <X /> Quitar
                                </Button>
                            </div>
                        </div>
                    ))}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={addContact}
                        >
                            <Plus /> Añadir contacto
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Otros</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4">
                    <FormField
                        label="Notas"
                        htmlFor="notes"
                        error={errors.notes}
                    >
                        <Textarea
                            id="notes"
                            maxLength={2000}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
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
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked)
                            }
                        />
                    </FormField>
                </CardContent>
            </Card>

            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    Guardar
                </Button>
                <Button variant="outline" asChild>
                    <Link href={index()}>Cancelar</Link>
                </Button>
            </div>
        </form>
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

SupplierForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Proveedores', href: index() },
        {
            title: props.supplier ? 'Editar proveedor' : 'Nuevo proveedor',
            href: props.supplier ? edit.url(props.supplier.id) : create.url(),
        },
    ],
});
