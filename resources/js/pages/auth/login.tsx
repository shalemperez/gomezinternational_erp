import { Head, usePage } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/auth/google';

type Props = { workspaceDomain: string | null };

export default function Login({ workspaceDomain }: Props) {
    const { flash } = usePage().props;

    return (
        <>
            <Head title="Iniciar sesión" />
            <div className="flex flex-col gap-6">
                {flash.error && (
                    <Alert variant="destructive">
                        <AlertDescription>{flash.error}</AlertDescription>
                    </Alert>
                )}
                <Button asChild className="w-full" size="lg">
                    <a href={redirect.url()}>Continuar con Google</a>
                </Button>
                {workspaceDomain && (
                    <p className="text-center text-sm text-muted-foreground">
                        Solo cuentas de Google Workspace de {workspaceDomain}
                    </p>
                )}
            </div>
        </>
    );
}

Login.layout = {
    title: 'Iniciar sesión',
    description: 'Usa tu cuenta de Google Workspace de la empresa.',
};
