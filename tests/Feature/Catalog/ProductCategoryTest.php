<?php

use App\Models\ProductCategory;

crudResourceTests([
    'label' => 'categories', 'routePrefix' => 'catalog.categories', 'component' => 'catalog/categories/index', 'model' => ProductCategory::class, 'table' => 'product_categories', 'routeParam' => 'category',
    'allowedRole' => 'owner', 'deniedIndexRole' => null, 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validCategoryPayload(), 'required' => ['name'], 'assertColumn' => 'name',
    'update' => ['field' => 'name', 'value' => 'Categoría Editada'], 'exportTable' => 'categories',
    'created' => 'Categoría creada', 'updated' => 'Categoría actualizada', 'deleted' => 'Categoría eliminada',
]);

test('category cannot be its own parent', function () {
    actingAsRole('owner');
    $category = ProductCategory::factory()->create();
    $this->put(route('catalog.categories.update', $category), validCategoryPayload(['name' => $category->name, 'parent_id' => $category->id]))
        ->assertSessionHasErrors(['parent_id' => 'Una categoría no puede tener como padre a sí misma ni a una de sus subcategorías.']);
});

test('category with children cannot be deleted', function () {
    actingAsRole('owner');
    $parent = ProductCategory::factory()->create();
    ProductCategory::factory()->create(['parent_id' => $parent->id]);
    $this->delete(route('catalog.categories.destroy', $parent))->assertRedirect()->assertSessionHas('error', 'No se puede eliminar: el registro está en uso');
    $this->assertDatabaseHas('product_categories', ['id' => $parent->id]);
});

test('a category cannot take one of its descendants as parent', function () {
    actingAsRole('owner');
    $a = ProductCategory::factory()->create(['name' => 'A']);
    $b = ProductCategory::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
    $c = ProductCategory::factory()->create(['name' => 'C', 'parent_id' => $b->id]);

    foreach ([$b, $c] as $descendant) {
        $this->put(route('catalog.categories.update', $a), validCategoryPayload(['name' => 'A', 'parent_id' => $descendant->id]))
            ->assertSessionHasErrors(['parent_id' => 'Una categoría no puede tener como padre a sí misma ni a una de sus subcategorías.']);
    }
    expect($a->fresh()->parent_id)->toBeNull();
});

test('edit form offers the current inactive parent and hides descendants', function () {
    actingAsRole('owner');
    $parent = ProductCategory::factory()->create(['name' => 'Padre', 'is_active' => false]);
    $category = ProductCategory::factory()->create(['name' => 'Hija', 'parent_id' => $parent->id]);
    $child = ProductCategory::factory()->create(['name' => 'Nieta', 'parent_id' => $category->id]);

    $this->get(route('catalog.categories.edit', $category))->assertOk()->assertInertia(fn ($p) => $p
        ->where('parents', fn ($parents) => collect($parents)->pluck('id')->contains($parent->id)
            && ! collect($parents)->pluck('id')->contains($category->id)
            && ! collect($parents)->pluck('id')->contains($child->id)));
});
