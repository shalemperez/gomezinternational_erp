<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseImporter
{
    abstract public static function type(): string;

    abstract public function label(): string;

    abstract public function permission(): string;

    /** @return list<array{key: string, label: string, required: bool, example: string}> */
    abstract public function columns(): array;

    /**
     * Maps user-facing values (labels, "Sí/No", codes) to the values the rules expect. Default: identity.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed> Laravel rules keyed by column key
     */
    abstract public function rules(array $row, array $meta): array;

    /** @return array<string, string> */
    public function messages(): array
    {
        return [];
    }

    /**
     * Value used for in-file duplicate detection and create/update resolution; null = always create.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    abstract public function naturalKey(array $row, array $meta): ?string;

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    abstract public function findExisting(array $row, array $meta): ?Model;

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    abstract public function persist(array $row, ?Model $existing, array $meta): Model;

    /**
     * Extra rules for the upload form (e.g. price_list_id).
     *
     * @return array<string, mixed>
     */
    public function metaRules(): array
    {
        return [];
    }

    /** @return array<string, string> label slug => key */
    public function labelMap(): array
    {
        $map = [];
        foreach ($this->columns() as $column) {
            $map[Str::slug($column['label'], '_')] = $column['key'];
        }

        return $map;
    }
}
