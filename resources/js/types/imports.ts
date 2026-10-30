export type ImporterInfo = {
    type: string;
    label: string;
    columns: { label: string; required: boolean }[];
    templateUrl: string;
    meta: { price_lists?: { id: number; name: string }[] };
};

export type ImportBatchRow = {
    id: number;
    row_number: number;
    data: Record<string, string | null>;
    errors: Record<string, string> | null;
    is_valid: boolean;
    action: 'create' | 'update' | null;
};

export type ImportBatchInfo = {
    id: number;
    type: string;
    label: string;
    original_name: string;
    status: 'previewed' | 'confirmed' | 'cancelled';
    status_label: string;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    created_count: number;
    updated_count: number;
    created_at: string;
    confirmed_at: string | null;
    user: { name: string };
};
