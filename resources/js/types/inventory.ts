export type Warehouse = {
    id: number;
    code: string;
    name: string;
    address: string | null;
    is_default: boolean;
    is_active: boolean;
};

export type InitialStockLine = {
    product_id: number;
    sku: string;
    name: string;
    tracks_lots: boolean;
    warehouse_id: string;
    quantity: string;
    unit_cost: string;
    lot_code: string;
    lot_expires_on: string;
};

export type DispatchStatus = 'draft' | 'confirmed';

export type DispatchLotOption = {
    id: number;
    product_id: number;
    code: string;
    expires_on: string | null;
    quantity: string;
    expired: boolean;
};

export type DispatchInvoiceLine = {
    id: number;
    product: {
        id: number;
        sku: string;
        name: string;
        tracks_lots: boolean;
    };
    quantity: string;
    dispatched_quantity: string;
    credited_quantity: string;
    remaining: string;
    suggested_lots: {
        lot_id: number;
        code: string;
        expires_on: string | null;
        quantity: string;
    }[];
};

export type DispatchInvoice = {
    id: number;
    number: string;
    ncf: string | null;
    client_name: string;
    warehouse: { id: number | null; name: string | null };
    lines: DispatchInvoiceLine[];
};

export type DispatchFormLine = {
    invoice_line_id: number;
    lot_id: string;
    quantity: string;
};

export type DispatchRow = {
    id: number;
    number: string | null;
    status: DispatchStatus;
    status_label: string;
    dispatch_date: string;
    driver_name: string | null;
    vehicle: string | null;
    invoice: { id: number; number: string; ncf: string | null } | null;
    client: { id: number; business_name: string } | null;
    warehouse: { id: number; name: string } | null;
};

export type DispatchDetail = Omit<
    DispatchRow,
    'invoice' | 'client' | 'warehouse'
> & {
    notes: string | null;
    has_expired_lots: boolean;
    total_cost?: string;
    confirmed_at: string | null;
    invoice: { id: number; number: string; ncf: string | null } | null;
    client: { id: number; business_name: string } | null;
    warehouse: { id: number; name: string } | null;
    confirmer: { id: number; name: string } | null;
    lines: {
        id: number;
        product: { id: number; sku: string; name: string } | null;
        lot: { id: number; code: string; expires_on: string | null } | null;
        quantity: string;
        unit_cost?: string;
        total_cost?: string;
    }[];
};

export type StockRow = {
    id: number;
    sku: string;
    name: string;
    warehouse: string;
    quantity: string;
    reserved_quantity: string;
    available: string;
    minimum_stock: string;
    is_low: boolean;
    average_cost?: string;
    stock_value?: string;
};

export type StockMovementRow = {
    id: number;
    moved_at: string | null;
    type: string;
    type_label: string;
    sku: string | null;
    name: string | null;
    warehouse: string | null;
    lot_code: string | null;
    signed_quantity: string;
    balance_after: string;
    reference_label: string;
    user_name: string | null;
    notes: string | null;
    unit_cost?: string;
    total_cost?: string;
};

export type LotState = 'expired' | 'expiring' | 'ok';

export type LotRow = {
    id: number;
    sku: string;
    name: string;
    lot_code: string;
    expires_on: string | null;
    warehouse: string;
    quantity: string;
    days_to_expiry: number | null;
    state: LotState;
};
