<?php

namespace App\Models;

use App\Exceptions\AppendOnlyAuditException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Activity
{
    protected $table = 'activity_log';

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyAuditException);
        static::deleting(fn () => throw new AppendOnlyAuditException);
    }

    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }
}
