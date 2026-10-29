<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductCategory;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends StoreProductCategoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ProductCategory $category */
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('product_categories', 'name')->ignore($category)],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id', Rule::notIn([$category->id])],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['parent_id.not_in' => 'Una categoría no puede ser su propia categoría padre.'];
    }
}
