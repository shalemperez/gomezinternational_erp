import { Badge } from '@/components/ui/badge';

const variants: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'secondary',
    sent: 'outline',
    pending: 'outline',
    partially_paid: 'outline',
    approved: 'default',
    issued: 'default',
    confirmed: 'default',
    posted: 'default',
    paid: 'default',
    invoiced: 'default',
    rejected: 'destructive',
    expired: 'destructive',
    cancelled: 'destructive',
};

export function StatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return <Badge variant={variants[status] ?? 'outline'}>{label}</Badge>;
}
