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

        // inventory (phase 1 subset)
        $add('inventory.warehouses.view', 'Ver almacenes', [...$all, 'warehouse']);
        $add('inventory.warehouses.manage', 'Administrar almacenes', ['owner', 'management']);
        $add('inventory.warehouses.export', 'Exportar almacenes', array_values(array_intersect([...$all, 'warehouse'], self::EXPORTERS)));

        return $p;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::permissions());
    }
}
