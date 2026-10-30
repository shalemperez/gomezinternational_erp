<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

final class AuditHistory
{
    /** @return list<array{id:int, created_at:string, causer:?string, impersonator:?string, event:?string, description:string, changes:list<array{field:string, old:mixed, new:mixed}>, properties:array<int|string, mixed>}> */
    public static function for(Model $model, int $limit = 100): array
    {
        $rows = [];
        $activities = ActivityLog::query()->with(['causer', 'impersonator'])->forSubject($model)->latest('id')->limit($limit)->get();
        $hideCosts = ! (auth()->user()?->can('inventory.stock.view-costs') ?? false);

        foreach ($activities as $a) {
            /** @var array<string, mixed> $old */
            $old = is_array($a->attribute_changes['old'] ?? null) ? $a->attribute_changes['old'] : [];
            /** @var array<string, mixed> $new */
            $new = is_array($a->attribute_changes['attributes'] ?? null) ? $a->attribute_changes['attributes'] : [];
            if ($hideCosts) {
                $old = self::withoutCosts($old);
                $new = self::withoutCosts($new);
            }
            /** @var list<string> $fields */
            $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
            $causer = $a->causer;

            $rows[] = [
                'id' => (int) $a->id,
                'created_at' => $a->created_at?->toIso8601String() ?? '',
                'causer' => $causer instanceof Model ? (string) $causer->getAttribute('name') : null,
                'impersonator' => $a->impersonator?->name,
                'event' => $a->event,
                'description' => (string) $a->description,
                'changes' => array_map(fn (string $f): array => ['field' => $f, 'old' => $old[$f] ?? null, 'new' => $new[$f] ?? null], $fields),
                'properties' => $hideCosts
                    ? self::withoutCosts(collect($a->properties ?? [])->mapWithKeys(fn (mixed $v, int|string $k): array => [(string) $k => $v])->all())
                    : collect($a->properties ?? [])->mapWithKeys(fn (mixed $v, int|string $k): array => [(string) $k => $v])->all(),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private static function withoutCosts(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_cost')) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::withoutCosts($value) : $value;
        }

        return $clean;
    }
}
