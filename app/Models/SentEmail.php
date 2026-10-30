<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SentEmail extends Model
{
    use LogsModelActivity;

    protected $fillable = ['user_id', 'mailable_type', 'mailable_id', 'template_key', 'to', 'cc', 'subject', 'body_html', 'attachments', 'status', 'gmail_message_id', 'gmail_thread_id', 'error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function mailable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
