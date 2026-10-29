<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

final class AuditHistory
{
    /** @return list<array{id:int, created_at:string, causer:?string, impersonator:?string, event:?string, description:string, changes:list<array{field:string, old:mixed, new:mixed}>, properties:array<string, mixed>}> */
    public static function for(Model $model, int $limit = 100): array
    {
        $rows = [];
        $activities = ActivityLog::query()->with(['causer', 'impersonator'])->forSubject($model)->latest('id')->limit($limit)->get();

        foreach ($activities as $a) {
            /** @var array<string, mixed> $old */
            $old = is_array($a->attribute_changes['old'] ?? null) ? $a->attribute_changes['old'] : [];
            /** @var array<string, mixed> $new */
            $new = is_array($a->attribute_changes['attributes'] ?? null) ? $a->attribute_changes['attributes'] : [];
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
                'properties' => collect($a->properties ?? [])->mapWithKeys(fn (mixed $v, int|string $k): array => [(string) $k => $v])->all(),
            ];
        }

        return $rows;
    }
}
