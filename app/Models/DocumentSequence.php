<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use LogsModelActivity;

    protected $fillable = ['kind', 'prefix', 'next_number'];

    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
