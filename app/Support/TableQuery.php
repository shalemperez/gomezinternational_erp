<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class TableQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $searchable
     * @param  list<string>  $sortable
     * @return Builder<TModel>
     */
    public static function applyFilters(Builder $query, Request $request, array $searchable, array $sortable, string $defaultSort = 'id', string $defaultDir = 'desc'): Builder
    {
        $search = $request->string('search')->trim()->toString();
        if ($search !== '' && $searchable !== []) {
            $query->where(function (Builder $q) use ($search, $searchable): void {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'ILIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
                }
            });
        }

        $sort = $request->string('sort', $defaultSort)->toString();
        $dir = strtolower($request->string('dir', $defaultDir)->toString()) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, $sortable, true)) {
            $sort = $defaultSort;
        }

        return $query->orderBy($sort, $dir)->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $searchable
     * @param  list<string>  $sortable
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function apply(Builder $query, Request $request, array $searchable, array $sortable, string $defaultSort = 'id', string $defaultDir = 'desc'): LengthAwarePaginator
    {
        $perPage = $request->integer('per_page', 15);
        $perPage = in_array($perPage, [15, 25, 50, 100], true) ? $perPage : 15;

        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = self::applyFilters($query, $request, $searchable, $sortable, $defaultSort, $defaultDir)
            ->paginate($perPage)
            ->withQueryString();

        return $paginator;
    }
}
