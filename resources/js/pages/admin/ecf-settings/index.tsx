import { Form, Head } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate } from '@/lib/format';
import certificateRoutes from '@/routes/admin/ecf-settings/certificate';
import { index, update } from '@/routes/admin/ecf-settings';
import type { EcfCertificateSummary, EcfSettingsValues } from '@/types/admin';

type Props = {
    values: EcfSettingsValues;
    hasAlanubeKey: boolean;
    certificate: EcfCertificateSummary | null;
    companyRnc: string | null;
    warnings: string[];
    documentCounts: Record<'test' | 'cert' | 'prod', number>;
    receiverUrls: { reception: string; approval: string };
    alanubeWebhook: { url: string; key: string | null };
    fiscalModeEffective: string;
};

export default function EcfSettingsIndex({
    values,
    hasAlanubeKey,
    certificate,
    warnings,
    documentCounts,
    receiverUrls,
    alanubeWebhook,
    fiscalModeEffective,
}: Props) {
    return (
        <>
            <Head title="Facturación electrónica" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Facturación electrónica"
                    description="Modo fiscal, proveedor, ambiente y certificado digital para emitir e-CF."
                />
                {warnings.map((warning) => (
                    <Alert key={warning} variant="destructive">
                        <AlertDescription>{warning}</AlertDescription>
                    </Alert>
                ))}
                <Tabs defaultValue="modo">
                    <TabsList>
                        <TabsTrigger value="modo">Modo fiscal</TabsTrigger>
                        <TabsTrigger value="proveedor">
                            Proveedor y ambiente
                        </TabsTrigger>
                        <TabsTrigger value="certificado">
                            Certificado digital
                        </TabsTrigger>
                        <TabsTrigger value="alanube">Alanube</TabsTrigger>
                        <TabsTrigger value="recepcion">
                            Servicios de recepción
                        </TabsTrigger>
                    </TabsList>

                    <Form {...update.form()} className="grid max-w-3xl gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TabsContent
                                    value="modo"
                                    forceMount
                                    className="grid gap-4 data-[state=inactive]:hidden"
                                >
                                    <FormField
                                        label="Modo fiscal"
                                        htmlFor="fiscal_mode_ncf"
                                        error={errors['fiscal.mode']}
                                        required
                                    >
                                        <div className="flex flex-col gap-2">
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    id="fiscal_mode_ncf"
                                                    type="radio"
                                                    name="fiscal[mode]"
                                                    value="ncf"
                                                    defaultChecked={
                                                        values[
                                                            'fiscal.mode'
                                                        ] !== 'ecf'
                                                    }
                                                />
                                                NCF tradicional
                                            </label>
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="radio"
                                                    name="fiscal[mode]"
                                                    value="ecf"
                                                    defaultChecked={
                                                        values[
                                                            'fiscal.mode'
                                                        ] === 'ecf'
                                                    }
                                                />
                                                e-CF (facturación electrónica)
                                            </label>
                                        </div>
                                    </FormField>
                                    <FormField
                                        label="Fecha de cambio a e-CF"
                                        htmlFor="ecf_cutover_date"
                                        error={
                                            errors['fiscal.ecf_cutover_date']
                                        }
                                        hint="Las facturas emitidas antes de esta fecha conservan su NCF tradicional."
                                    >
                                        <Input
                                            id="ecf_cutover_date"
                                            name="fiscal[ecf_cutover_date]"
                                            type="date"
                                            defaultValue={String(
                                                values[
                                                    'fiscal.ecf_cutover_date'
                                                ] ?? '',
                                            )}
                                        />
                                    </FormField>
                                    <p className="text-sm text-muted-foreground">
                                        Modo fiscal vigente:{' '}
                                        {fiscalModeEffective === 'ecf'
                                            ? 'e-CF'
                                            : 'NCF tradicional'}
                                    </p>
                                </TabsContent>

                                <TabsContent
                                    value="proveedor"
                                    forceMount
                                    className="grid gap-4 data-[state=inactive]:hidden"
                                >
                                    <FormField
                                        label="Proveedor"
                                        htmlFor="ecf_provider"
                                        error={errors['ecf.provider']}
                                        required
                                    >
                                        <Select
                                            name="ecf[provider]"
                                            defaultValue={String(
                                                values['ecf.provider'] ??
                                                    'fake',
                                            )}
                                        >
                                            <SelectTrigger id="ecf_provider">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="fake">
                                                    Simulado (pruebas)
                                                </SelectItem>
                                                <SelectItem value="dgii">
                                                    DGII directo
                                                </SelectItem>
                                                <SelectItem value="alanube">
                                                    Alanube (PSFE)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                    <FormField
                                        label="Ambiente"
                                        htmlFor="ecf_environment"
                                        error={errors['ecf.environment']}
                                        required
                                    >
                                        <Select
                                            name="ecf[environment]"
                                            defaultValue={String(
                                                values['ecf.environment'] ??
                                                    'test',
                                            )}
                                        >
                                            <SelectTrigger id="ecf_environment">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="test">
                                                    Pruebas (TesteCF)
                                                </SelectItem>
                                                <SelectItem value="cert">
                                                    Certificación (CerteCF)
                                                </SelectItem>
                                                <SelectItem value="prod">
                                                    Producción (eCF)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </FormField>
                                    <FormField
                                        label="Umbral de RFCE (RD$)"
                                        htmlFor="ecf_rfce_threshold"
                                        error={errors['ecf.rfce_threshold']}
                                        hint="Las facturas de consumo por debajo de este monto se envían como RFCE."
                                        required
                                    >
                                        <Input
                                            id="ecf_rfce_threshold"
                                            name="ecf[rfce_threshold]"
                                            inputMode="decimal"
                                            defaultValue={String(
                                                values['ecf.rfce_threshold'] ??
                                                    '',
                                            )}
                                            required
                                        />
                                    </FormField>
                                    <FormField
                                        label="Horas para alertar comprobantes pendientes"
                                        htmlFor="ecf_pending_alert_hours"
                                        error={
                                            errors['ecf.pending_alert_hours']
                                        }
                                        required
                                    >
                                        <Input
                                            id="ecf_pending_alert_hours"
                                            name="ecf[pending_alert_hours]"
                                            type="number"
                                            min={1}
                                            max={72}
                                            defaultValue={String(
                                                values[
                                                    'ecf.pending_alert_hours'
                                                ] ?? 24,
                                            )}
                                            required
                                        />
                                    </FormField>
                                    <FormField
                                        label="Municipio (código)"
                                        htmlFor="ecf_company_municipio"
                                        error={errors['ecf.company.municipio']}
                                    >
                                        <Input
                                            id="ecf_company_municipio"
                                            name="ecf[company][municipio]"
                                            defaultValue={String(
                                                values[
                                                    'ecf.company.municipio'
                                                ] ?? '',
                                            )}
                                        />
                                    </FormField>
                                    <FormField
                                        label="Provincia (código)"
                                        htmlFor="ecf_company_provincia"
                                        error={errors['ecf.company.provincia']}
                                    >
                                        <Input
                                            id="ecf_company_provincia"
                                            name="ecf[company][provincia]"
                                            defaultValue={String(
                                                values[
                                                    'ecf.company.provincia'
                                                ] ?? '',
                                            )}
                                        />
                                    </FormField>
                                    <p className="text-sm text-muted-foreground">
                                        Comprobantes emitidos — Pruebas:{' '}
                                        {documentCounts.test}, Certificación:{' '}
                                        {documentCounts.cert}, Producción:{' '}
                                        {documentCounts.prod}
                                    </p>
                                </TabsContent>

                                <TabsContent
                                    value="alanube"
                                    forceMount
                                    className="grid gap-4 data-[state=inactive]:hidden"
                                >
                                    <FormField
                                        label="API key de Alanube"
                                        htmlFor="ecf_alanube_api_key"
                                        error={errors['ecf.alanube.api_key']}
                                        hint={
                                            hasAlanubeKey
                                                ? 'Deja vacío para conservar la clave registrada.'
                                                : 'Se guarda cifrada y no se vuelve a mostrar.'
                                        }
                                    >
                                        <div className="flex items-center gap-2">
                                            <Input
                                                id="ecf_alanube_api_key"
                                                name="ecf[alanube][api_key]"
                                                type="password"
                                                autoComplete="off"
                                            />
                                            {hasAlanubeKey && (
                                                <Badge variant="secondary">
                                                    Clave registrada
                                                </Badge>
                                            )}
                                        </div>
                                    </FormField>

                                    <div className="grid gap-4 border-t pt-4">
                                        <p className="text-sm text-muted-foreground">
                                            Registra esta URL en Alanube como
                                            webhook del evento
                                            documents.emissionFinished, con la
                                            cabecera x-api-key igual a la clave.
                                        </p>
                                        <ReceiverUrl
                                            label="URL Webhook Alanube"
                                            value={alanubeWebhook.url}
                                        />
                                        {alanubeWebhook.key !== null ? (
                                            <ReceiverUrl
                                                label="Clave x-api-key"
                                                value={alanubeWebhook.key}
                                            />
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Guarda la configuración con el
                                                proveedor Alanube para generar
                                                la clave del webhook.
                                            </p>
                                        )}
                                    </div>
                                </TabsContent>

                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Guardar
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>

                    <TabsContent
                        value="certificado"
                        className="grid max-w-3xl gap-4"
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>Certificado actual</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 text-sm">
                                {certificate ? (
                                    <>
                                        <p>
                                            <span className="font-medium">
                                                {certificate.name}
                                            </span>{' '}
                                            <Badge
                                                variant={
                                                    certificate.is_active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {certificate.is_active
                                                    ? 'Activo'
                                                    : 'Inactivo'}
                                            </Badge>
                                            {certificate.is_expired && (
                                                <Badge
                                                    variant="destructive"
                                                    className="ml-2"
                                                >
                                                    Vencido
                                                </Badge>
                                            )}
                                        </p>
                                        <p>Sujeto: {certificate.subject}</p>
                                        <p>Emisor: {certificate.issuer}</p>
                                        <p>
                                            Serie: {certificate.serial_number}
                                        </p>
                                        <p>RNC: {certificate.rnc ?? '—'}</p>
                                        <p>
                                            Vigencia:{' '}
                                            {formatDate(certificate.valid_from)}{' '}
                                            al{' '}
                                            {formatDate(certificate.valid_to)}
                                        </p>
                                        {certificate.is_active && (
                                            <Form
                                                {...certificateRoutes.deactivate.form(
                                                    certificate.id,
                                                )}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        Desactivar certificado
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        No hay certificado digital cargado.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Cargar certificado (.p12)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    {...certificateRoutes.store.form()}
                                    encType="multipart/form-data"
                                    className="grid gap-4"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <FormField
                                                label="Archivo .p12 o .pfx"
                                                htmlFor="ecf_certificate_file"
                                                error={errors.certificate}
                                                required
                                            >
                                                <Input
                                                    id="ecf_certificate_file"
                                                    name="certificate"
                                                    type="file"
                                                    accept=".p12,.pfx"
                                                    required
                                                />
                                            </FormField>
                                            <FormField
                                                label="Contraseña del certificado"
                                                htmlFor="ecf_certificate_password"
                                                error={errors.password}
                                                required
                                            >
                                                <Input
                                                    id="ecf_certificate_password"
                                                    name="password"
                                                    type="password"
                                                    autoComplete="off"
                                                    required
                                                />
                                            </FormField>
                                            <FormField
                                                label="Nombre"
                                                htmlFor="ecf_certificate_name"
                                                error={errors.name}
                                            >
                                                <Input
                                                    id="ecf_certificate_name"
                                                    name="name"
                                                    maxLength={100}
                                                />
                                            </FormField>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Cargar y activar
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent
                        value="recepcion"
                        className="grid max-w-3xl gap-4"
                    >
                        <Card>
                            <CardContent className="grid gap-4 pt-6">
                                <p className="text-sm text-muted-foreground">
                                    Registra estas URL en el formulario de
                                    postulación de la DGII (URL Recepción / URL
                                    Aprobación).
                                </p>
                                <ReceiverUrl
                                    label="URL Recepción"
                                    value={receiverUrls.reception}
                                />
                                <ReceiverUrl
                                    label="URL Aprobación"
                                    value={receiverUrls.approval}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

function ReceiverUrl({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-2">
            <span className="text-sm font-medium">{label}</span>
            <div className="flex items-center gap-2">
                <Input value={value} readOnly />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={`Copiar ${label}`}
                    onClick={() => navigator.clipboard.writeText(value)}
                >
                    <Copy />
                </Button>
            </div>
        </div>
    );
}

EcfSettingsIndex.layout = {
    breadcrumbs: [{ title: 'Facturación electrónica', href: index() }],
};
