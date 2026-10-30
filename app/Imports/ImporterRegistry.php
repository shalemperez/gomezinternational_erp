<?php

namespace App\Imports;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ImporterRegistry
{
    /** @var list<class-string<BaseImporter>> */
    private const IMPORTERS = [ClientsImporter::class, SuppliersImporter::class, ProductsImporter::class, PriceListItemsImporter::class, InitialStockImporter::class];

    /** @return array<string, BaseImporter> */
    public static function all(): array
    {
        $all = [];
        foreach (self::IMPORTERS as $class) {
            $all[$class::type()] = app($class);
        }

        return $all;
    }

    public static function get(string $type): BaseImporter
    {
        return self::all()[$type] ?? throw new NotFoundHttpException("Importador desconocido: {$type}");
    }
}
