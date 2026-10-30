import { Form, Head } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index, update } from '@/routes/admin/settings';
import type { SettingsValues } from '@/types/admin';

type Props = {
    values: SettingsValues;
    canManage: boolean;
    logoUrl: string | null;
};

export default function SettingsIndex({ values, canManage, logoUrl }: Props) {
    return (
        <>
            <Head title="Configuración" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Configuración" />
                <Form
                    {...update.form()}
                    encType="multipart/form-data"
                    className="grid max-w-2xl gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Tabs defaultValue="empresa">
                                <TabsList>
                                    <TabsTrigger value="empresa">
                                        Empresa
                                    </TabsTrigger>
                                    <TabsTrigger value="tasas">
                                        Tasas de cambio
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent
                                    value="empresa"
                                    forceMount
                                    className="grid gap-4 data-[state=inactive]:hidden"
                                >
                                    <FormField
                                        label="Nombre comercial"
                                        htmlFor="company_name"
                                        error={errors['company.name']}
                                        required
                                    >
                                        <Input
                                            id="company_name"
                                            name="company[name]"
                                            defaultValue={
                                                values['company.name'] ?? ''
                                            }
                                            disabled={!canManage}
                                            required
                                        />
                                    </FormField>
                                    <FormField
                                        label="Razón social"
                                        htmlFor="company_legal_name"
                                        error={errors['company.legal_name']}
                                    >
                                        <Input
                                            id="company_legal_name"
                                            name="company[legal_name]"
                                            defaultValue={
                                                values['company.legal_name'] ??
                                                ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="RNC"
                                        htmlFor="company_rnc"
                                        error={errors['company.rnc']}
                                    >
                                        <Input
                                            id="company_rnc"
                                            name="company[rnc]"
                                            defaultValue={
                                                values['company.rnc'] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Dirección"
                                        htmlFor="company_address"
                                        error={errors['company.address']}
                                    >
                                        <Input
                                            id="company_address"
                                            name="company[address]"
                                            defaultValue={
                                                values['company.address'] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Teléfono"
                                        htmlFor="company_phone"
                                        error={errors['company.phone']}
                                    >
                                        <Input
                                            id="company_phone"
                                            name="company[phone]"
                                            defaultValue={
                                                values['company.phone'] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Correo"
                                        htmlFor="company_email"
                                        error={errors['company.email']}
                                    >
                                        <Input
                                            id="company_email"
                                            name="company[email]"
                                            type="email"
                                            defaultValue={
                                                values['company.email'] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Logo"
                                        htmlFor="logo"
                                        error={errors.logo}
                                    >
                                        {logoUrl && (
                                            <img
                                                src={logoUrl}
                                                alt="Logo de la empresa"
                                                className="h-16"
                                            />
                                        )}
                                        <Input
                                            id="logo"
                                            name="logo"
                                            type="file"
                                            accept="image/*"
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                </TabsContent>
                                <TabsContent
                                    value="tasas"
                                    forceMount
                                    className="grid gap-4 data-[state=inactive]:hidden"
                                >
                                    <FormField
                                        label="Fuente"
                                        htmlFor="exchange_source"
                                        error={errors['exchange_rates.source']}
                                        required
                                    >
                                        <Select
                                            name="exchange_rates[source]"
                                            defaultValue={
                                                values[
                                                    'exchange_rates.source'
                                                ] ?? ''
                                            }
                                            disabled={!canManage}
                                        >
                                            <SelectTrigger id="exchange_source">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="manual">
                                                    Manual
                                                </SelectItem>
                                                <SelectItem value="http_json">
                                                    Servicio externo (HTTP JSON)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                    <FormField
                                        label="URL"
                                        htmlFor="http_json_url"
                                        error={
                                            errors[
                                                'exchange_rates.http_json.url'
                                            ]
                                        }
                                        hint="Usa {currency} y {date} como marcadores; ej.: https://api.ejemplo.com/rates/{currency}/{date}"
                                    >
                                        <Input
                                            id="http_json_url"
                                            name="exchange_rates[http_json][url]"
                                            defaultValue={
                                                values[
                                                    'exchange_rates.http_json.url'
                                                ] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Ruta JSON"
                                        htmlFor="http_json_path"
                                        error={
                                            errors[
                                                'exchange_rates.http_json.json_path'
                                            ]
                                        }
                                        hint="Ruta con puntos hasta el valor numérico de la tasa; ej.: data.sell"
                                    >
                                        <Input
                                            id="http_json_path"
                                            name="exchange_rates[http_json][json_path]"
                                            defaultValue={
                                                values[
                                                    'exchange_rates.http_json.json_path'
                                                ] ?? ''
                                            }
                                            disabled={!canManage}
                                        />
                                    </FormField>
                                </TabsContent>
                            </Tabs>
                            {canManage && (
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Guardar
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'Configuración', href: index() }],
};
