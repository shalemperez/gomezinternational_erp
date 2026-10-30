<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use LogsModelActivity;

    protected $fillable = ['key', 'subject', 'body_html'];
}
