<?php

namespace App\Exports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TableExport extends DefaultValueBinder implements FromQuery, WithCustomCsvSettings, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison
{
    use Exportable;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  array<string, string|Closure>  $columns  heading => dot path on the model, or Closure(Model): mixed
     */
    public function __construct(private readonly Builder $builder, private readonly array $columns, private readonly string $format = 'xlsx') {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string|Closure>  $columns
     */
    public static function fromQuery(Builder $query, string $baseName, array $columns, string $format = 'xlsx'): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $fileName = $baseName.'-'.now()->format('Ymd-His').'.'.$format;

        activity('exports')->event('exported')->withProperties([
            'table' => $baseName,
            'format' => $format,
            'filters' => request()->query(),
            'row_count' => (clone $query)->toBase()->getCountForPagination(),
        ])->log("Exportación de {$baseName} ({$format})");

        return (new self($query, $columns, $format))->download($fileName, $format === 'csv' ? Excel::CSV : Excel::XLSX);
    }

    public function query(): Builder
    {
        return $this->builder;
    }

    public function headings(): array
    {
        return array_keys($this->columns);
    }

    /** @param Model $row */
    public function map(mixed $row): array
    {
        return array_map(function ($accessor) use ($row): mixed {
            $value = $accessor instanceof Closure ? $accessor($row) : data_get($row, $accessor);

            return $this->format === 'csv' ? self::csvSafe($value) : $value;
        }, array_values($this->columns));
    }

    /** CSV cells are typed by the consumer, so active content must be neutralised with a leading apostrophe. */
    public static function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** xlsx: every non-numeric string is stored as a literal string, never evaluated as a formula. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && ! is_numeric($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ',', 'use_bom' => true];
    }
}
