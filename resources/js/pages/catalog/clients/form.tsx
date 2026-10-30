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
import { create, edit, index, store, update } from '@/routes/catalog/clients';
import type { AuditEntry } from '@/types/audit';
import type { Client, ClientContact } from '@/types/catalog';

type Props = {
    client?: Client;
    history?: AuditEntry[];
    salespeople: { id: number; name: string }[];
    priceLists: { id: number; name: string }[];
    currencies: { id: number; code: string; name: string }[];
    taxIdTypes: { value: string; label: string }[];
    taxpayerTypes: { value: string; label: string }[];
    defaultCurrencyId: number;
    lockSalesperson: boolean;
    canOverride: boolean;
};

type ClientFormData = {
    code: string;
    business_name: string;
    trade_name: string;
    tax_id_type: string;
    tax_id: string;
    taxpayer_type: string;
    tax_id_override: boolean;
    tax_id_override_reason: string;
    address: string;
    city: string;
    phone: string;
    email: string;
    salesperson_id: string;
    price_list_id: string;
    currency_id: string;
    credit_days: string;
    credit_limit: string;
    contacts: ClientContact[];
    notes: string;
    is_active: boolean;
};

export default function ClientForm({
    client,
    history,
    salespeople,
    priceLists,
    currencies,
    taxIdTypes,
    taxpayerTypes,
    defaultCurrencyId,
    lockSalesperson,
    canOverride,
}: Props) {
    const title = client ? 'Editar cliente' : 'Nuevo cliente';
    const { data, setData, post, put, processing, errors } =
        useForm<ClientFormData>({
            code: client?.code ?? '',
            business_name: client?.business_name ?? '',
            trade_name: client?.trade_name ?? '',
            tax_id_type: client?.tax_id_type ?? 'rnc',
            tax_id: client?.formatted_tax_id ?? '',
            taxpayer_type: client?.taxpayer_type ?? 'juridica',
            tax_id_override: false,
            tax_id_override_reason: '',
            address: client?.address ?? '',
            city: client?.city ?? '',
            phone: client?.phone ?? '',
            email: client?.email ?? '',
            salesperson_id: client?.salesperson_id
                ? String(client.salesperson_id)
                : 'none',
            price_list_id: client?.price_list_id
                ? String(client.price_list_id)
                : 'none',
            currency_id: String(client?.currency_id ?? defaultCurrencyId),
            credit_days: String(client?.credit_days ?? 30),
            credit_limit: client?.credit_limit ?? '0',
            contacts: (client?.contacts ?? []).map((contact) => ({
                id: contact.id ?? null,
                name: contact.name,
                position: contact.position,
                email: contact.email,
                phone: contact.phone,
                is_primary: contact.is_primary,
            })),
            notes: client?.notes ?? '',
            is_active: client?.is_active ?? true,
        });

    const updateContact = (index: number, changes: Partial<ClientContact>) => {
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

        if (client) {
            put(update.url(client.id));
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
                        label="Razón social"
                        htmlFor="business_name"
                        error={errors.business_name}
                        required
                    >
                        <Input
                            id="business_name"
                            maxLength={200}
                            value={data.business_name}
                            onChange={(e) =>
                                setData('business_name', e.target.value)
                            }
                            required
                        />
                    </FormField>
                    <FormField
                        label="Nombre comercial"
                        htmlFor="trade_name"
                        error={errors.trade_name}
                    >
                        <Input
                            id="trade_name"
                            maxLength={200}
                            value={data.trade_name}
                            onChange={(e) =>
                                setData('trade_name', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Tipo de identificación"
                        htmlFor="tax_id_type"
                        error={errors.tax_id_type}
                        required
                    >
                        <Select
                            value={data.tax_id_type}
                            onValueChange={(value) =>
                                setData('tax_id_type', value)
                            }
                        >
                            <SelectTrigger id="tax_id_type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {taxIdTypes.map((type) => (
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
                        label="RNC/Cédula"
                        htmlFor="tax_id"
                        error={errors.tax_id}
                    >
                        <Input
                            id="tax_id"
                            maxLength={20}
                            disabled={data.tax_id_type === 'none'}
                            value={data.tax_id}
                            onChange={(e) => setData('tax_id', e.target.value)}
                        />
                    </FormField>
                    <FormField
                        label="Tipo de contribuyente"
                        htmlFor="taxpayer_type"
                        error={errors.taxpayer_type}
                        required
                    >
                        <Select
                            value={data.taxpayer_type}
                            onValueChange={(value) =>
                                setData('taxpayer_type', value)
                            }
                        >
                            <SelectTrigger id="taxpayer_type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {taxpayerTypes.map((type) => (
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
                    {canOverride && (
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
                    {client?.tax_id_validation_overridden && (
                        <div className="md:col-span-2">
                            <Badge
                                variant="outline"
                                title={`${client.tax_id_override_reason ?? ''} — ${client.tax_id_override_by_name ?? ''} ${formatDateTime(client.tax_id_override_at)}`}
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
                        label="Ciudad"
                        htmlFor="city"
                        error={errors.city}
                    >
                        <Input
                            id="city"
                            maxLength={100}
                            value={data.city}
                            onChange={(e) => setData('city', e.target.value)}
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
                        label="Vendedor"
                        htmlFor="salesperson_id"
                        error={errors.salesperson_id}
                    >
                        <Select
                            value={data.salesperson_id}
                            disabled={lockSalesperson}
                            onValueChange={(value) =>
                                setData('salesperson_id', value)
                            }
                        >
                            <SelectTrigger id="salesperson_id">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Sin vendedor
                                </SelectItem>
                                {salespeople.map((salesperson) => (
                                    <SelectItem
                                        key={salesperson.id}
                                        value={String(salesperson.id)}
                                    >
                                        {salesperson.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Lista de precios"
                        htmlFor="price_list_id"
                        error={errors.price_list_id}
                    >
                        <Select
                            value={data.price_list_id}
                            onValueChange={(value) =>
                                setData('price_list_id', value)
                            }
                        >
                            <SelectTrigger id="price_list_id">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Lista predeterminada
                                </SelectItem>
                                {priceLists.map((priceList) => (
                                    <SelectItem
                                        key={priceList.id}
                                        value={String(priceList.id)}
                                    >
                                        {priceList.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
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
                    <FormField
                        label="Límite de crédito"
                        htmlFor="credit_limit"
                        error={errors.credit_limit}
                        required
                    >
                        <Input
                            id="credit_limit"
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.credit_limit}
                            onChange={(e) =>
                                setData('credit_limit', e.target.value)
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
                                        `contacts.${index}.name` as keyof ClientFormData
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

ClientForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Clientes', href: index() },
        {
            title: props.client ? 'Editar cliente' : 'Nuevo cliente',
            href: props.client ? edit.url(props.client.id) : create.url(),
        },
    ],
});
