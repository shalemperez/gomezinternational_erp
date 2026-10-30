<?php

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

crudResourceTests([
    'label' => 'price lists', 'routePrefix' => 'catalog.price-lists', 'component' => 'catalog/price-lists/index', 'model' => PriceList::class, 'table' => 'price_lists', 'routeParam' => 'priceList',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'warehouse', 'deniedStoreRole' => 'sales',
    'payload' => fn () => validPriceListPayload(), 'required' => ['name', 'currency_id'], 'assertColumn' => 'name',
    'update' => ['field' => 'name', 'value' => 'Lista editada'], 'exportTable' => 'price-lists',
    'created' => 'Lista de precios creada', 'updated' => 'Lista de precios actualizada', 'deleted' => 'Lista de precios eliminada',
]);

test('only one default list', function () {
    actingAsRole('owner');
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['name' => 'A', 'is_default' => 'on']));
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['name' => 'B', 'is_default' => 'on']));

    expect(PriceList::where('name', 'A')->first()->is_default)->toBeFalse()
        ->and(PriceList::where('name', 'B')->first()->is_default)->toBeTrue()
        ->and(PriceList::default()->name)->toBe('B');
});

test('items are upserted, empty prices delete rows through the model, and changes are logged', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    [$p1, $p2] = Product::factory()->count(2)->create();

    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p1->id, 'price' => '150.5'], ['product_id' => $p2->id, 'price' => '']]])
        ->assertRedirect(route('catalog.price-lists.items', $list))->assertSessionHas('success', 'Precios guardados');
    expect(PriceListItem::where('price_list_id', $list->id)->count())->toBe(1)
        ->and(PriceListItem::where('product_id', $p1->id)->first()->price)->toBe('150.5000');

    $item = PriceListItem::where('product_id', $p1->id)->firstOrFail();
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p1->id, 'price' => '']]]);
    expect(PriceListItem::where('price_list_id', $list->id)->count())->toBe(0)
        ->and(Activity::inLog('price_list_items')->where('event', 'created')->where('subject_id', $item->id)->exists())->toBeTrue()
        ->and(Activity::inLog('price_list_items')->where('event', 'deleted')->where('subject_id', $item->id)->exists())->toBeTrue()
        ->and(Activity::inLog('price_lists')->where('event', 'items_updated')->where('subject_id', $list->id)->count())->toBe(2);
});

test('items validation rejects negative prices and duplicate products', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '-1']]])->assertSessionHasErrors(['items.0.price']);
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '1'], ['product_id' => $p->id, 'price' => '2']]])->assertSessionHasErrors(['items.1.product_id']);
});

test('items page lists products with current prices', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p->id, 'price' => '99.0000']);
    $this->get(route('catalog.price-lists.items', $list))->assertOk()->assertInertia(fn ($pg) => $pg->component('catalog/price-lists/items')->where('products.0.price', '99.0000'));
});

test('items export lists sku name price and is logged', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => Product::factory()->create(['sku' => 'ABC-1', 'name' => 'Cosa'])->id, 'price' => '10.0000']);
    $response = $this->get(route('catalog.price-lists.items.export', [$list, 'format' => 'csv']));
    $response->assertOk();
    expect(file_get_contents($response->getFile()->getPathname()))->toContain('"SKU","Producto","Precio"')->toContain('"ABC-1","Cosa","10"')
        ->and(Activity::inLog('exports')->where('properties->table', 'price-list-items')->exists())->toBeTrue();
});

test('the default price list must be active', function () {
    actingAsRole('owner');
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['is_default' => 'on', 'is_active' => '0']))
        ->assertSessionHasErrors(['is_default' => 'La lista predeterminada debe estar activa.']);
});

test('an inactive non default price list can be saved', function () {
    actingAsRole('owner');
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['is_default' => '0', 'is_active' => '0']))
        ->assertSessionHasNoErrors()->assertRedirect();
});

test('deleting a price list logs each removed item', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $item = PriceListItem::factory()->create(['price_list_id' => $list->id]);

    $this->delete(route('catalog.price-lists.destroy', $list))->assertRedirect();
    expect(Activity::query()->where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)->where('event', 'deleted')->exists())->toBeTrue();
});

test('saving items without changes writes no items_updated row', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $before = Activity::inLog('price_lists')->where('event', 'items_updated')->count();

    $this->put(route('catalog.price-lists.items.update', $list), ['items' => []])->assertRedirect();
    expect(Activity::inLog('price_lists')->where('event', 'items_updated')->count())->toBe($before);
});

test('sales can view the items page read-only but cannot save prices', function () {
    actingAsRole('sales');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p->id, 'price' => '5.0000']);
    $this->get(route('catalog.price-lists.items', $list))->assertOk()->assertInertia(fn ($pg) => $pg->where('canEdit', false)->where('products.0.price', '5.0000'));
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '1']]])->assertForbidden();
});

test('items validation uses one query for product ids and reports unknown and duplicated products', function () {
    actingAsRole('owner');
    $list = PriceList::factory()->create();
    $p = Product::factory()->create();
    DB::enableQueryLog();
    $this->put(route('catalog.price-lists.items.update', $list), ['items' => [['product_id' => $p->id, 'price' => '1'], ['product_id' => 999999, 'price' => '2'], ['product_id' => $p->id, 'price' => '3']]])
        ->assertSessionHasErrors(['items.1.product_id' => 'El producto no existe.', 'items.2.product_id' => 'Producto repetido en la lista.']);
    $productQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "products"'))->count();
    DB::disableQueryLog();
    expect($productQueries)->toBe(1);
});

test('the database refuses a second default price list', function () {
    PriceList::factory()->create(['is_default' => true]);
    expect(fn () => DB::transaction(fn () => PriceList::factory()->create(['is_default' => true])))->toThrow(QueryException::class);
    actingAsRole('owner');
    $this->post(route('catalog.price-lists.store'), validPriceListPayload(['name' => 'Nueva predeterminada', 'is_default' => 'on']))->assertRedirect(route('catalog.price-lists.index'));
    expect(PriceList::where('is_default', true)->count())->toBe(1)->and(PriceList::default()->name)->toBe('Nueva predeterminada');
});
