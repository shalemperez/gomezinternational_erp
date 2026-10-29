export type AuditChange = { field: string; old: unknown; new: unknown };

export type AuditEntry = {
    id: number;
    created_at: string;
    causer: string | null;
    impersonator: string | null;
    event: string | null;
    description: string;
    changes: AuditChange[];
    properties: Record<string, unknown>;
};
