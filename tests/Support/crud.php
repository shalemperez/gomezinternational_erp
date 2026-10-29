<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Registers the 10 shared CRUD tests of GC-7 for one resource.
 *
 * @param array{
 *   label: string,                 // test name prefix, e.g. 'brands'
 *   routePrefix: string,           // e.g. 'catalog.brands'
 *   component: string,             // Inertia index component, e.g. 'catalog/brands/index'
 *   model: class-string<\Illuminate\Database\Eloquent\Model>,
 *   table: string,                 // DB table = activity log name
 *   routeParam: string,            // resource route parameter name, e.g. 'brand'
 *   allowedRole: string,           // role that can do everything on the resource
 *   deniedIndexRole: ?string,      // role that cannot view; null = a user with no roles
 *   deniedStoreRole: string,       // role that can view but not create
 *   payload: Closure(): array,     // valid store payload (may create related rows)
 *   required: list<string>,        // fields reported when posting []
 *   assertColumn: string,          // column asserted after store, taken from the payload
 *   update: array{field: string, value: string},
 *   exportTable: string,           // properties.table written by TableExport
 *   created: string, updated: string, deleted: string   // flash messages
 * } $cfg
 */
function crudResourceTests(array $cfg): void
{
    $L = $cfg['label'];
    $r = $cfg['routePrefix'];
    $model = $cfg['model'];

    test("$L: index is forbidden without permission", function () use ($cfg, $r) {
        if ($cfg['deniedIndexRole'] === null) {
            $this->actingAs(User::factory()->create());
        } else {
            actingAsRole($cfg['deniedIndexRole']);
        }
        $this->get(route("$r.index"))->assertForbidden();
    });

    test("$L: index renders for an allowed role", function () use ($cfg, $r, $model) {
        actingAsRole($cfg['allowedRole']);
        $model::factory()->create();
        $this->get(route("$r.index"))->assertOk()->assertInertia(fn ($p) => $p->component($cfg['component'])->has('rows.data', 1)->has('filters'));
    });

    test("$L: store is forbidden without the create permission", function () use ($cfg, $r) {
        actingAsRole($cfg['deniedStoreRole']);
        $this->post(route("$r.store"), ($cfg['payload'])())->assertForbidden();
    });

    test("$L: store validates required fields", function () use ($cfg, $r) {
        actingAsRole($cfg['allowedRole']);
        $this->post(route("$r.store"), [])->assertSessionHasErrors($cfg['required']);
    });

    test("$L: store creates the record and logs it", function () use ($cfg, $r, $model) {
        $actor = actingAsRole($cfg['allowedRole']);
        $payload = ($cfg['payload'])();
        $this->post(route("$r.store"), $payload)->assertRedirect(route("$r.index"))->assertSessionHas('success', $cfg['created']);
        $this->assertDatabaseHas($cfg['table'], [$cfg['assertColumn'] => $payload[$cfg['assertColumn']]]);
        $row = $model::query()->where($cfg['assertColumn'], $payload[$cfg['assertColumn']])->firstOrFail();
        expect(Activity::inLog($cfg['table'])->where('event', 'created')->where('subject_id', $row->id)->where('causer_id', $actor->id)->exists())->toBeTrue();
    });

    test("$L: update changes the record and logs old and new values", function () use ($cfg, $r, $model) {
        $actor = actingAsRole($cfg['allowedRole']);
        $row = $model::factory()->create();
        $field = $cfg['update']['field'];
        $before = $row->getAttribute($field);
        $payload = array_merge(($cfg['payload'])(), [$field => $cfg['update']['value']]);
        $this->put(route("$r.update", [$cfg['routeParam'] => $row]), $payload)->assertRedirect(route("$r.index"))->assertSessionHas('success', $cfg['updated']);
        expect((string) $row->fresh()->getAttribute($field))->toBe($cfg['update']['value']);
        $activity = Activity::inLog($cfg['table'])->where('event', 'updated')->where('subject_id', $row->id)->latest('id')->firstOrFail();
        expect($activity->causer_id)->toBe($actor->id)
            ->and((string) $activity->attribute_changes['old'][$field])->toBe((string) $before)
            ->and((string) $activity->attribute_changes['attributes'][$field])->toBe($cfg['update']['value']);
    });

    test("$L: destroy deletes the record and logs it", function () use ($cfg, $r, $model) {
        actingAsRole($cfg['allowedRole']);
        $row = $model::factory()->create();
        $this->delete(route("$r.destroy", [$cfg['routeParam'] => $row]))->assertRedirect(route("$r.index"))->assertSessionHas('success', $cfg['deleted']);
        $this->assertDatabaseMissing($cfg['table'], ['id' => $row->id]);
        expect(Activity::inLog($cfg['table'])->where('event', 'deleted')->where('subject_id', $row->id)->exists())->toBeTrue();
    });

    test("$L: export downloads an xlsx file", function () use ($cfg, $r, $model) {
        actingAsRole($cfg['allowedRole']);
        $model::factory()->create();
        $response = $this->get(route("$r.export", ['format' => 'xlsx']));
        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('spreadsheetml');
    });

    test("$L: export is logged", function () use ($cfg, $r, $model) {
        actingAsRole($cfg['allowedRole']);
        $model::factory()->create();
        $this->get(route("$r.export", ['format' => 'csv']))->assertOk();
        $log = Activity::inLog('exports')->where('event', 'exported')->latest('id')->firstOrFail();
        expect($log->properties['table'])->toBe($cfg['exportTable'])->and($log->properties['format'])->toBe('csv')->and($log->properties['row_count'])->toBe(1);
    });

    test("$L: edit page includes the record history", function () use ($cfg, $r, $model) {
        actingAsRole($cfg['allowedRole']);
        $row = $model::factory()->create();
        $this->get(route("$r.edit", [$cfg['routeParam'] => $row]))->assertOk()->assertInertia(fn ($p) => $p->has('history'));
    });
}
