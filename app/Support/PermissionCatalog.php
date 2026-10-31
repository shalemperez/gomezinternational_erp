<?php

namespace App\Support;

final class PermissionCatalog
{
    public const SUPER_ADMIN = 'super-admin';

    /** @var array<string, string> role name => Spanish label */
    public const ROLES = [
        'super-admin' => 'Super Admin',
        'owner' => 'Dueña',
        'management' => 'Gerencia',
        'sales' => 'Ventas',
        'accounting' => 'Contabilidad',
        'warehouse' => 'Almacén',
    ];

    /** Roles that receive every non-admin permission by default. */
    private const BUSINESS_ALL = ['owner', 'management', 'accounting'];

    /** Roles allowed to export tables by default (spec: warehouse never exports). */
    private const EXPORTERS = ['owner', 'management', 'accounting', 'sales'];

    /** @return array<string, string> */
    public static function modules(): array
    {
        return [
            'admin' => 'Administración',
            'catalog' => 'Catálogo',
            'finance' => 'Finanzas',
            'inventory' => 'Inventario',
            'sales' => 'Ventas',
            'fiscal' => 'Fiscal',
        ];
    }

    /** @return array<string, array{label: string, module: string, roles: list<string>}> */
    public static function permissions(): array
    {
        $p = [];
        $add = function (string $name, string $label, array $roles) use (&$p): void {
            $p[$name] = ['label' => $label, 'module' => explode('.', $name)[0], 'roles' => array_values(array_unique($roles))];
        };
        $crud = function (string $prefix, string $plural, array $viewRoles, array $writeRoles, bool $import = false) use ($add): void {
            $add("$prefix.view", "Ver $plural", $viewRoles);
            $add("$prefix.create", "Crear $plural", $writeRoles);
            $add("$prefix.update", "Editar $plural", $writeRoles);
            $add("$prefix.delete", "Eliminar $plural", array_values(array_diff($writeRoles, ['sales'])));
            $add("$prefix.export", "Exportar $plural", array_values(array_intersect($viewRoles, self::EXPORTERS)));
            if ($import) {
                $add("$prefix.import", "Importar $plural", array_values(array_diff($writeRoles, ['sales'])));
            }
        };

        $all = self::BUSINESS_ALL;

        // admin — spec §2: Gerencia gets nothing under admin.*
        $add('admin.users.view', 'Ver usuarios', ['owner']);
        $add('admin.users.create', 'Invitar usuarios', ['owner']);
        $add('admin.users.update', 'Editar usuarios', ['owner']);
        $add('admin.users.deactivate', 'Desactivar usuarios', ['owner']);
        $add('admin.users.impersonate', 'Entrar como otro usuario', []);
        $add('admin.roles.view', 'Ver roles', ['owner']);
        $add('admin.roles.update', 'Editar permisos de roles', ['owner']);
        $add('admin.settings.view', 'Ver configuración', ['owner', 'accounting']);
        $add('admin.settings.manage', 'Editar configuración', ['owner']);
        $add('admin.activity-log.view', 'Ver bitácora', ['owner', 'accounting']);
        $add('admin.activity-log.export', 'Exportar bitácora', ['owner']);
        $add('admin.system.manage', 'Administrar sistema (técnico)', []);

        // catalog
        $crud('catalog.clients', 'clientes', [...$all, 'sales'], [...$all, 'sales'], true);
        $add('catalog.clients.view-all', 'Ver clientes de todos los vendedores', $all);
        $crud('catalog.suppliers', 'proveedores', $all, $all, true);
        $crud('catalog.brands', 'marcas', [...$all, 'sales', 'warehouse'], $all);
        $crud('catalog.categories', 'categorías', [...$all, 'sales', 'warehouse'], $all);
        $crud('catalog.units', 'unidades de medida', [...$all, 'warehouse'], $all);
        $crud('catalog.products', 'productos', [...$all, 'sales', 'warehouse'], $all, true);
        $add('catalog.products.view-costs', 'Ver costos de productos', $all);
        $crud('catalog.price-lists', 'listas de precios', [...$all, 'sales'], $all, true);
        $crud('catalog.salespeople', 'vendedores', [...$all, 'sales'], $all);
        $crud('catalog.additional-taxes', 'impuestos adicionales', $all, $all);
        $add('catalog.tax-ids.override-validation', 'Forzar RNC/cédula que no pasa la validación', ['owner']);

        // finance
        $add('finance.currencies.manage', 'Administrar monedas', $all);
        $add('finance.exchange-rates.view', 'Ver tasas de cambio', [...$all, 'sales']);
        $add('finance.exchange-rates.manage', 'Registrar tasas de cambio', $all);
        $add('finance.accounts.view', 'Ver cuentas bancarias y cajas', $all);
        $add('finance.accounts.manage', 'Administrar cuentas bancarias y cajas', $all);
        $add('finance.accounts.export', 'Exportar cuentas bancarias y cajas', array_values(array_intersect($all, self::EXPORTERS)));

        // inventory (phase 1 subset)
        $add('inventory.warehouses.view', 'Ver almacenes', [...$all, 'warehouse']);
        $add('inventory.warehouses.manage', 'Administrar almacenes', ['owner', 'management']);
        $add('inventory.warehouses.export', 'Exportar almacenes', array_values(array_intersect([...$all, 'warehouse'], self::EXPORTERS)));

        // sales (phase 2)
        $docs = function (string $prefix, string $plural, array $viewRoles, array $createRoles, array $extra) use ($add, $all): void {
            $add("$prefix.view", "Ver $plural", $viewRoles);
            $add("$prefix.view-all", "Ver $plural de todos los vendedores", $all);
            $add("$prefix.create", "Crear $plural", $createRoles);
            $add("$prefix.update", "Editar $plural en borrador", $createRoles);
            $add("$prefix.export", "Exportar $plural", array_values(array_intersect($viewRoles, self::EXPORTERS)));
            foreach ($extra as $action => [$label, $roles]) {
                $add("$prefix.$action", $label, $roles);
            }
        };
        $docs('sales.proformas', 'proformas', [...$all, 'sales'], [...$all, 'sales'], ['approve' => ['Aprobar proformas', [...$all, 'sales']], 'delete' => ['Eliminar proformas en borrador', [...$all, 'sales']], 'send' => ['Enviar proformas por correo', [...$all, 'sales']]]);
        $docs('sales.invoices', 'facturas', [...$all, 'sales'], [...$all, 'sales'], ['issue' => ['Emitir facturas', [...$all, 'sales']], 'cancel' => ['Anular facturas', $all], 'send' => ['Enviar facturas por correo', [...$all, 'sales']], 'credit-override' => ['Autorizar exceso de límite de crédito', ['owner', 'management']], 'delete' => ['Eliminar facturas en borrador', [...$all, 'sales']]]);
        $add('sales.credit-notes.view', 'Ver notas de crédito', [...$all, 'sales']);
        $add('sales.credit-notes.create', 'Crear y emitir notas de crédito', $all);
        $add('sales.credit-notes.export', 'Exportar notas de crédito', $all);
        $add('sales.debit-notes.view', 'Ver notas de débito', [...$all, 'sales']);
        $add('sales.debit-notes.create', 'Crear y emitir notas de débito', $all);
        $add('sales.debit-notes.export', 'Exportar notas de débito', $all);
        $add('sales.receipts.view', 'Ver recibos de ingreso', [...$all, 'sales']);
        $add('sales.receipts.create', 'Registrar recibos de ingreso', $all);
        $add('sales.receipts.cancel', 'Anular recibos de ingreso', ['owner', 'management', 'accounting']);
        $add('sales.receipts.export', 'Exportar recibos', $all);
        $add('sales.statements.view', 'Ver estados de cuenta y antigüedad', [...$all, 'sales']);
        $add('sales.statements.send', 'Enviar estados de cuenta por correo', [...$all, 'sales']);
        $add('sales.historical-invoices.import', 'Importar facturas históricas', ['owner', 'accounting']);

        // fiscal (phase 2 subset)
        $add('fiscal.sequences.view', 'Ver secuencias NCF', $all);
        $add('fiscal.sequences.manage', 'Administrar secuencias NCF', ['owner', 'accounting']);
        $add('fiscal.mode.manage', 'Cambiar modo fiscal (NCF/e-CF)', ['owner']);

        // fiscal e-CF (phase 3)
        $add('fiscal.ecf.view', 'Ver comprobantes electrónicos (e-CF)', [...$all, 'sales']);
        $add('fiscal.ecf.manage', 'Reintentar y consultar e-CF ante la DGII', $all);
        $add('fiscal.ecf.export', 'Exportar comprobantes electrónicos', $all);
        $add('fiscal.ecf.settings', 'Configurar facturación electrónica (proveedor, ambiente, certificado)', ['owner']);
        $add('fiscal.ecf.annul', 'Anular secuencias e-NCF no utilizadas', ['owner', 'accounting']);
        $add('fiscal.ecf.certification', 'Ejecutar el proceso de certificación DGII', ['owner', 'accounting']);
        $add('fiscal.ecf.alerts', 'Recibir alertas de e-CF rechazados o pendientes', ['owner', 'management', 'accounting']);
        $add('fiscal.received-ecf.view', 'Ver e-CF recibidos de proveedores', $all);
        $add('fiscal.received-ecf.approve', 'Aprobar o rechazar comercialmente e-CF recibidos', ['owner', 'accounting']);
        $add('fiscal.received-ecf.export', 'Exportar e-CF recibidos', $all);

        // inventory (phase 2 subset)
        $add('inventory.stock.view', 'Ver existencias', [...$all, 'sales', 'warehouse']);
        $add('inventory.stock.view-costs', 'Ver costos de inventario', $all);
        $add('inventory.movements.view', 'Ver movimientos de inventario (kardex)', [...$all, 'warehouse']);
        $add('inventory.lots.view', 'Ver lotes y vencimientos', [...$all, 'sales', 'warehouse']);
        $add('inventory.dispatches.view', 'Ver conduces', [...$all, 'sales', 'warehouse']);
        $add('inventory.dispatches.create', 'Crear conduces', [...$all, 'warehouse']);
        $add('inventory.dispatches.confirm', 'Confirmar conduces (salida de almacén)', [...$all, 'warehouse']);
        $add('inventory.dispatches.expired-override', 'Autorizar despacho de lotes vencidos', ['owner', 'management']);
        $add('inventory.initial-stock.import', 'Cargar existencias iniciales', ['owner', 'accounting', 'warehouse']);
        $add('inventory.alerts.receive', 'Recibir alertas de stock mínimo y vencimientos', ['owner', 'management', 'warehouse']);
        $add('inventory.dispatches.export', 'Exportar conduces', array_values(array_intersect([...$all, 'sales', 'warehouse'], self::EXPORTERS)));
        $add('inventory.stock.export', 'Exportar existencias', array_values(array_intersect([...$all, 'sales', 'warehouse'], self::EXPORTERS)));
        $add('inventory.movements.export', 'Exportar movimientos de inventario', $all);

        return $p;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::permissions());
    }
}
