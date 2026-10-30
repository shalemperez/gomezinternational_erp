<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $response
 * @property bool $succeeded
 */
class EcfTransmission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'environment', 'http_status', 'request_path', 'response', 'response_path', 'succeeded', 'error'];

    protected function casts(): array
    {
        return ['response' => 'array', 'succeeded' => 'boolean', 'created_at' => 'datetime'];
    }
}
