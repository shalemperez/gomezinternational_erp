<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ActivityLogController extends Controller
{
    #[Middleware('permission:admin.activity-log.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filtered($request), $request, [], ['created_at'], 'created_at', 'desc')
            ->through(fn (ActivityLog $a) => $this->row($a));

        return Inertia::render('admin/activity-log/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'causer_id', 'log_name', 'subject_type', 'subject_id', 'event', 'date_from', 'date_to']),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'logNames' => Cache::remember('activity-log:log-names', 300, fn () => ActivityLog::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->all()),
            'events' => Cache::remember('activity-log:events', 300, fn () => ActivityLog::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all()),
        ]);
    }

    #[Middleware('permission:admin.activity-log.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filtered($request), $request, [], ['created_at'], 'created_at', 'desc');

        return TableExport::fromQuery($query, 'activity-log', [
            'Fecha' => fn (ActivityLog $a) => $a->created_at->format('Y-m-d H:i:s'),
            'Usuario' => fn (ActivityLog $a) => $a->causer instanceof User ? $a->causer->name : 'Sistema',
            'Suplantado por' => fn (ActivityLog $a) => $a->impersonator?->name,
            'Registro' => 'log_name',
            'Evento' => 'event',
            'Descripción' => 'description',
            'Objeto' => fn (ActivityLog $a) => $a->subject_type ? class_basename($a->subject_type).' #'.$a->subject_id : null,
            'Cambios' => fn (ActivityLog $a) => $a->attribute_changes ? json_encode($a->attribute_changes, JSON_UNESCAPED_UNICODE) : null,
            'Propiedades' => fn (ActivityLog $a) => $a->properties?->isNotEmpty() ? json_encode($a->properties, JSON_UNESCAPED_UNICODE) : null,
            'IP' => 'ip',
            'Request' => 'request_id',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<ActivityLog> */
    private function filtered(Request $request): Builder
    {
        $query = ActivityLog::query()->with(['causer:id,name', 'impersonator:id,name']);
        $causerId = $request->string('causer_id')->toString();
        if (ctype_digit($causerId)) {
            $query->where('causer_id', (int) $causerId);
        }
        if (($log = $request->string('log_name')->toString()) !== '') {
            $query->where('log_name', $log);
        }
        if (($event = $request->string('event')->toString()) !== '') {
            $query->where('event', $event);
        }
        $type = trim($request->string('subject_type')->toString(), '\\ ');
        if ($type !== '') {
            // exact match on the morph class: short name ("Client") or full class name
            $query->where(fn (Builder $q) => $q->where('subject_type', $type)->orWhere('subject_type', 'App\\Models\\'.$type));
        }
        $subjectId = $request->string('subject_id')->toString();
        if (ctype_digit($subjectId)) {
            $query->where('subject_id', (int) $subjectId);
        }
        $from = $request->string('date_from')->toString();
        if ($from !== '' && Carbon::canBeCreatedFromFormat($from, 'Y-m-d')) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $from)->startOfDay());
        }
        $to = $request->string('date_to')->toString();
        if ($to !== '' && Carbon::canBeCreatedFromFormat($to, 'Y-m-d')) {
            $query->where('created_at', '<', Carbon::createFromFormat('Y-m-d', $to)->addDay()->startOfDay());
        }
        if (($search = $request->string('search')->trim()->toString()) !== '') {
            $query->where('description', 'ILIKE', '%'.addcslashes($search, '\\%_').'%');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(ActivityLog $a): array
    {
        $old = $a->attribute_changes['old'] ?? [];
        $new = $a->attribute_changes['attributes'] ?? [];
        $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

        return [
            'id' => $a->id,
            'created_at' => $a->created_at->toIso8601String(),
            'causer' => $a->causer instanceof User ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
            'impersonator' => $a->impersonator ? ['id' => $a->impersonator->id, 'name' => $a->impersonator->name] : null,
            'ip' => $a->ip,
            'request_id' => $a->request_id,
            'log_name' => $a->log_name,
            'event' => $a->event,
            'description' => $a->description,
            'subject' => $a->subject_type ? class_basename($a->subject_type).' #'.$a->subject_id : null,
            'changes' => array_map(fn (int|string $f) => ['field' => (string) $f, 'old' => $old[$f] ?? null, 'new' => $new[$f] ?? null], $fields),
            'properties' => $a->properties?->toArray() ?? [],
        ];
    }
}
