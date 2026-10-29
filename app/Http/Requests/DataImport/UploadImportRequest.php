<?php

namespace App\Http\Requests\DataImport;

use App\Imports\ImporterRegistry;
use Illuminate\Foundation\Http\FormRequest;

class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']] + ImporterRegistry::get((string) $this->route('type'))->metaRules();
    }
}
