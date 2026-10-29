import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

type Props = {
    label: string;
    htmlFor: string;
    error?: string;
    required?: boolean;
    hint?: string;
    children: ReactNode;
    className?: string;
};

export function FormField({
    label,
    htmlFor,
    error,
    required,
    hint,
    children,
    className,
}: Props) {
    return (
        <div className={className ?? 'grid gap-2'}>
            <Label htmlFor={htmlFor}>
                {label}
                {required && <span className="text-red-600"> *</span>}
            </Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={error} />
        </div>
    );
}
