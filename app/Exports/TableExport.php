<?php

namespace App\Exports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TableExport implements FromQuery, WithCustomCsvSettings, WithHeadings, WithMapping, WithStrictNullComparison
{
    use Exportable;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  array<string, string|Closure>  $columns  heading => dot path on the model, or Closure(Model): mixed
     */
    public function __construct(private readonly Builder $builder, private readonly array $columns) {}

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

        return (new self($query, $columns))->download($fileName, $format === 'csv' ? Excel::CSV : Excel::XLSX);
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
        return array_map(fn ($accessor) => $accessor instanceof Closure ? $accessor($row) : data_get($row, $accessor), array_values($this->columns));
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ',', 'use_bom' => true];
    }
}
