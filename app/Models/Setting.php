<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;

class Setting extends Model
{
    use LogsModelActivity {
        getActivitylogOptions as defaultActivitylogOptions;
    }

    /** Settings whose value never reaches the activity log (GC-18, GC-21). */
    public const SECRET_KEYS = ['ecf.alanube.api_key', 'ecf.alanube.webhook_key'];

    protected $fillable = ['key', 'value'];

    public function getActivitylogOptions(): LogOptions
    {
        $options = $this->defaultActivitylogOptions();

        if (in_array($this->getAttribute('key'), self::SECRET_KEYS, true)) {
            return $options->logExcept(['password', 'remember_token', 'value']);
        }

        return $options;
    }

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
