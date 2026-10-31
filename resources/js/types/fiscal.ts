export type NcfSequenceRow = {
    id: number;
    ncf_type: string;
    label: string;
    range_from: number;
    range_to: number;
    next_number: number;
    remaining: number;
    used: number;
    expires_on: string | null;
    authorization_number: string | null;
    is_active: boolean;
    exhausted_at: string | null;
    notes: string | null;
    environment: string;
    environment_label: string;
    sequence_digits: number;
};

export type NcfTypeOption = {
    value: string;
    label: string;
    expires: boolean;
    electronic: boolean;
    sequence_digits: number;
};

export type SequenceAlert = {
    ncf_type: string;
    label: string;
    remaining: number;
    expires_on: string | null;
    reason: 'low' | 'expiring' | 'expired' | 'missing';
};
