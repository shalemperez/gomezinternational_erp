<?php

namespace App\Services\Ecf;

use App\Exceptions\EcfException;
use App\Models\EcfDocument;
use Illuminate\Support\Facades\Storage;

/** Write-once file storage for every e-CF, RFCE and DGII response (GC-18). */
class EcfStorage
{
    public function documentDir(EcfDocument $document): string
    {
        return 'ecf/'.$document->environment->value.'/'.$document->created_at->year.'/'.$document->encf;
    }

    /** @throws EcfException when a file already exists at the path */
    public function putNew(string $path, string $contents): string
    {
        if ($this->exists($path)) {
            throw new EcfException("El archivo {$path} ya existe y es inmutable");
        }

        Storage::disk(config('ecf.storage_disk'))->put($path, $contents);

        return $path;
    }

    public function get(string $path): string
    {
        return Storage::disk(config('ecf.storage_disk'))->get($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(config('ecf.storage_disk'))->exists($path);
    }
}
