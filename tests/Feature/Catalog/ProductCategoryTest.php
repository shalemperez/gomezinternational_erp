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
        ->assertSessionHasErrors(['parent_id' => 'Una categoría no puede ser su propia categoría padre.']);
});

test('category with children cannot be deleted', function () {
    actingAsRole('owner');
    $parent = ProductCategory::factory()->create();
    ProductCategory::factory()->create(['parent_id' => $parent->id]);
    $this->delete(route('catalog.categories.destroy', $parent))->assertRedirect()->assertSessionHas('error', 'No se puede eliminar: el registro está en uso');
    $this->assertDatabaseHas('product_categories', ['id' => $parent->id]);
});
