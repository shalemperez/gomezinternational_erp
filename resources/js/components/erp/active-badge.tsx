import { Badge } from '@/components/ui/badge';

export function ActiveBadge({ active }: { active: boolean }) {
    return (
        <Badge variant={active ? 'default' : 'secondary'}>
            {active ? 'Activo' : 'Inactivo'}
        </Badge>
    );
}
