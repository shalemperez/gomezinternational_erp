export type EcfStatus =
    | 'pending'
    | 'signed'
    | 'sent'
    | 'in_process'
    | 'accepted'
    | 'conditionally_accepted'
    | 'rejected'
    | 'cancelled';

export type EcfDocumentRef = {
    kind: 'invoice' | 'credit_note' | 'debit_note';
    id: number;
    number: string | null;
    client_name: string | null;
    total: string;
    currency: string | null;
    url: string;
};

export type EcfSummary = {
    id: number;
    encf: string;
    status: EcfStatus;
    status_label: string;
    flow: string;
    track_id: string | null;
    security_code: string | null;
    signed_at: string | null;
    qr_url: string | null;
    dgii_messages: { codigo: string; valor: string }[] | null;
    last_error: string | null;
    attempts: number;
    next_attempt_at: string | null;
    is_valid: boolean;
    show_url: string;
};

export type EcfDocumentRow = {
    id: number;
    encf: string;
    ncf_type: string;
    type_label: string;
    flow: string;
    status: EcfStatus;
    status_label: string;
    environment: string;
    track_id: string | null;
    attempts: number;
    created_at: string | null;
    signed_at: string | null;
    document: EcfDocumentRef;
};

export type EcfDocumentDetail = EcfSummary & {
    commercial_status: string | null;
    commercial_reason: string | null;
    commercial_at: string | null;
    dgii_code: number | null;
    environment_label: string;
    xml_url: string | null;
    rfce_url: string | null;
    document: EcfDocumentRef;
};

export type EcfTransmissionRow = {
    id: number;
    kind: string;
    http_status: number | null;
    succeeded: boolean;
    error: string | null;
    created_at: string | null;
    url: string;
};

export type EcfAnnulmentPendingRange = {
    id: number;
    ncf_type: string;
    range_from: number;
    range_to: number;
    encf_from: string;
    encf_to: string;
    quantity: number;
    reason: string;
    document_number: string | null;
};

export type EcfAnnulmentHistoryRow = {
    id: number;
    status: string;
    status_label: string;
    sent_at: string | null;
    ranges_count: number;
    quantity: number;
    messages: string[];
};
