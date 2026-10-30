import { Form, Head } from '@inertiajs/react';
import { FormField } from '@/components/erp/form-field';
import { PageHeader } from '@/components/erp/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index, update } from '@/routes/admin/email-templates';
import type { EmailTemplate } from '@/types/mail';

const TEMPLATE_LABELS: Record<string, string> = {
    proforma: 'Proforma',
    invoice: 'Factura',
    statement: 'Estado de cuenta',
    credit_note: 'Nota de crédito',
};

type Props = {
    templates: EmailTemplate[];
    placeholders: string[];
};

export default function EmailTemplatesIndex({
    templates,
    placeholders,
}: Props) {
    const placeholderHint = placeholders
        .map((name) => `{{${name}}}`)
        .join(', ');

    return (
        <>
            <Head title="Plantillas de correo" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Plantillas de correo" />
                {templates.map((template) => (
                    <Card key={template.key} className="max-w-2xl">
                        <CardHeader>
                            <CardTitle>
                                {TEMPLATE_LABELS[template.key] ?? template.key}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...update.form(template.key)}
                                className="grid gap-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <FormField
                                            label="Asunto"
                                            htmlFor={`subject-${template.key}`}
                                            error={errors.subject}
                                            required
                                        >
                                            <Input
                                                id={`subject-${template.key}`}
                                                name="subject"
                                                defaultValue={template.subject}
                                                required
                                            />
                                        </FormField>
                                        <FormField
                                            label="Cuerpo (HTML)"
                                            htmlFor={`body-${template.key}`}
                                            error={errors.body_html}
                                            hint={`Marcadores disponibles: ${placeholderHint}`}
                                            required
                                        >
                                            <Textarea
                                                id={`body-${template.key}`}
                                                name="body_html"
                                                rows={8}
                                                defaultValue={
                                                    template.body_html
                                                }
                                                required
                                            />
                                        </FormField>
                                        <div className="flex gap-2">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Guardar
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

EmailTemplatesIndex.layout = {
    breadcrumbs: [{ title: 'Plantillas de correo', href: index() }],
};
