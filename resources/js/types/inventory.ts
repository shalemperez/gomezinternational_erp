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
