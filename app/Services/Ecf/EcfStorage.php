<?php

namespace App\Services\Ecf;

use App\Exceptions\EcfException;
use App\Models\EcfDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * Only for files written while the document row is being created. `ecf_documents` is unique on (encf, environment), so an existing file can only be a leftover of an issue whose transaction rolled back and released the e-NCF.
     */
    public function putForDocument(EcfDocument $document, string $fileName, string $contents): string
    {
        $directory = $this->documentDir($document);
        $path = "{$directory}/{$fileName}";

        if ($this->exists($path)) {
            $orphanedPath = "{$directory}/orphaned/".Str::uuid()->toString().'-'.$fileName;
            Storage::disk(config('ecf.storage_disk'))->move($path, $orphanedPath);
            Log::warning('Archivo e-CF huérfano movido', ['path' => $orphanedPath]);
        }

        return $this->putNew($path, $contents);
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
