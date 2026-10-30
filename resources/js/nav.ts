import {
    ArrowLeftRight,
    Banknote,
    Boxes,
    Building2,
    CalendarClock,
    ClipboardList,
    Coins,
    FileMinus,
    FilePlus,
    FileSpreadsheet,
    FileText,
    HandCoins,
    Hash,
    LayoutGrid,
    Layers,
    Mail,
    Package,
    PackageCheck,
    Percent,
    Receipt,
    Ruler,
    Scale,
    Settings,
    ShieldCheck,
    Tags,
    Truck,
    UserCog,
    Users,
    Warehouse,
} from 'lucide-react';
import type { NavItem } from '@/types';

export type NavGroup = {
    label: string;
    items: (NavItem & { permission?: string })[];
};

export const navGroups: NavGroup[] = [
    {
        label: 'General',
        items: [{ title: 'Panel', href: '/dashboard', icon: LayoutGrid }],
    },
    {
        label: 'Catálogo',
        items: [
            {
                title: 'Clientes',
                href: '/catalog/clients',
                icon: Users,
                permission: 'catalog.clients.view',
            },
            {
                title: 'Proveedores',
                href: '/catalog/suppliers',
                icon: Truck,
                permission: 'catalog.suppliers.view',
            },
            {
                title: 'Productos',
                href: '/catalog/products',
                icon: Package,
                permission: 'catalog.products.view',
            },
            {
                title: 'Marcas',
                href: '/catalog/brands',
                icon: Tags,
                permission: 'catalog.brands.view',
            },
            {
                title: 'Categorías',
                href: '/catalog/categories',
                icon: Boxes,
                permission: 'catalog.categories.view',
            },
            {
                title: 'Unidades de medida',
                href: '/catalog/units',
                icon: Ruler,
                permission: 'catalog.units.view',
            },
            {
                title: 'Impuestos adicionales',
                href: '/catalog/additional-taxes',
                icon: Percent,
                permission: 'catalog.additional-taxes.view',
            },
            {
                title: 'Listas de precios',
                href: '/catalog/price-lists',
                icon: ClipboardList,
                permission: 'catalog.price-lists.view',
            },
            {
                title: 'Vendedores',
                href: '/catalog/salespeople',
                icon: UserCog,
                permission: 'catalog.salespeople.view',
            },
        ],
    },
    {
        label: 'Ventas',
        items: [
            {
                title: 'Proformas',
                href: '/sales/proformas',
                icon: FileText,
                permission: 'sales.proformas.view',
            },
            {
                title: 'Facturas',
                href: '/sales/invoices',
                icon: Receipt,
                permission: 'sales.invoices.view',
            },
            {
                title: 'Notas de crédito',
                href: '/sales/credit-notes',
                icon: FileMinus,
                permission: 'sales.credit-notes.view',
            },
            {
                title: 'Notas de débito',
                href: '/sales/debit-notes',
                icon: FilePlus,
                permission: 'sales.debit-notes.view',
            },
            {
                title: 'Recibos de ingreso',
                href: '/sales/receipts',
                icon: HandCoins,
                permission: 'sales.receipts.view',
            },
            {
                title: 'Estados de cuenta',
                href: '/sales/statements',
                icon: Scale,
                permission: 'sales.statements.view',
            },
        ],
    },
    {
        label: 'Finanzas',
        items: [
            {
                title: 'Monedas y tasas',
                href: '/finance/exchange-rates',
                icon: Coins,
                permission: 'finance.exchange-rates.view',
            },
            {
                title: 'Cuentas bancarias y cajas',
                href: '/finance/accounts',
                icon: Banknote,
                permission: 'finance.accounts.view',
            },
        ],
    },
    {
        label: 'Inventario',
        items: [
            {
                title: 'Almacenes',
                href: '/inventory/warehouses',
                icon: Warehouse,
                permission: 'inventory.warehouses.view',
            },
            {
                title: 'Existencias',
                href: '/inventory/stock',
                icon: Layers,
                permission: 'inventory.stock.view',
            },
            {
                title: 'Movimientos',
                href: '/inventory/movements',
                icon: ArrowLeftRight,
                permission: 'inventory.movements.view',
            },
            {
                title: 'Lotes',
                href: '/inventory/lots',
                icon: CalendarClock,
                permission: 'inventory.lots.view',
            },
            {
                title: 'Conduces',
                href: '/inventory/dispatches',
                icon: PackageCheck,
                permission: 'inventory.dispatches.view',
            },
        ],
    },
    {
        label: 'Administración',
        items: [
            {
                title: 'Usuarios',
                href: '/admin/users',
                icon: Users,
                permission: 'admin.users.view',
            },
            {
                title: 'Roles y permisos',
                href: '/admin/roles',
                icon: ShieldCheck,
                permission: 'admin.roles.view',
            },
            {
                title: 'Configuración',
                href: '/admin/settings',
                icon: Settings,
                permission: 'admin.settings.view',
            },
            {
                title: 'Secuencias NCF',
                href: '/admin/ncf-sequences',
                icon: Hash,
                permission: 'fiscal.sequences.view',
            },
            {
                title: 'Plantillas de correo',
                href: '/admin/email-templates',
                icon: Mail,
                permission: 'admin.settings.manage',
            },
            {
                title: 'Importar datos',
                href: '/admin/imports',
                icon: FileSpreadsheet,
                permission: 'catalog.clients.import',
            },
            {
                title: 'Bitácora',
                href: '/admin/activity-log',
                icon: Building2,
                permission: 'admin.activity-log.view',
            },
        ],
    },
];
