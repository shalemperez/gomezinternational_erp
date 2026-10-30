import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FormField } from '@/components/erp/form-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { connect } from '@/routes/auth/gmail';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    defaultTo: string[];
    defaultSubject?: string;
    templateKey: string;
    submitUrl: string;
    attachmentsLabel: string;
};

export function SendEmailDialog({
    open,
    onOpenChange,
    defaultTo,
    defaultSubject,
    submitUrl,
    attachmentsLabel,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Enviar por correo</DialogTitle>
                </DialogHeader>
                <SendEmailForm
                    defaultTo={defaultTo}
                    defaultSubject={defaultSubject}
                    submitUrl={submitUrl}
                    attachmentsLabel={attachmentsLabel}
                    onDone={() => onOpenChange(false)}
                    onCancel={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    defaultTo: string[];
    defaultSubject?: string;
    submitUrl: string;
    attachmentsLabel: string;
    onDone: () => void;
    onCancel: () => void;
};

function SendEmailForm({
    defaultTo,
    defaultSubject,
    submitUrl,
    attachmentsLabel,
    onDone,
    onCancel,
}: FormProps) {
    const { auth } = usePage().props;
    const [to, setTo] = useState(defaultTo.join(', '));
    const [cc, setCc] = useState('');
    const [subject, setSubject] = useState(defaultSubject ?? '');
    const [body, setBody] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(
            submitUrl,
            { to, cc, subject, body },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );
    };

    if (!auth.gmail_connected) {
        return (
            <Alert>
                <AlertDescription>
                    Conecta tu cuenta de Gmail para enviar correos.{' '}
                    <a href={connect.url()} className="underline">
                        Conectar Gmail
                    </a>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            <DialogDescription>
                Se enviará desde tu Gmail ({auth.gmail_email}){' '}
                {attachmentsLabel}.
            </DialogDescription>
            <FormField label="Para" htmlFor="mail-to" required>
                <Input
                    id="mail-to"
                    value={to}
                    onChange={(event) => setTo(event.target.value)}
                    required
                />
            </FormField>
            <FormField label="CC" htmlFor="mail-cc">
                <Input
                    id="mail-cc"
                    value={cc}
                    onChange={(event) => setCc(event.target.value)}
                />
            </FormField>
            <FormField label="Asunto" htmlFor="mail-subject">
                <Input
                    id="mail-subject"
                    value={subject}
                    placeholder="Vacío = asunto de la plantilla"
                    onChange={(event) => setSubject(event.target.value)}
                />
            </FormField>
            <FormField label="Mensaje" htmlFor="mail-body">
                <Textarea
                    id="mail-body"
                    rows={6}
                    value={body}
                    placeholder="Vacío = mensaje de la plantilla"
                    onChange={(event) => setBody(event.target.value)}
                />
            </FormField>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancelar
                </Button>
                <Button type="submit" disabled={processing}>
                    Enviar
                </Button>
            </DialogFooter>
        </form>
    );
}
