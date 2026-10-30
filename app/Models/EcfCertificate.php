<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\EcfCertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $name
 * @property string $subject
 * @property string $issuer
 * @property string $serial_number
 * @property string|null $rnc
 * @property string $fingerprint_sha256
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property string $p12_encrypted
 * @property string $password_encrypted
 * @property bool $is_active
 */
class EcfCertificate extends Model
{
    /** @use HasFactory<EcfCertificateFactory> */
    use HasFactory, LogsModelActivity {
        LogsModelActivity::getActivitylogOptions as protected baseActivitylogOptions;
    }

    protected $fillable = ['name', 'subject', 'issuer', 'serial_number', 'rnc', 'fingerprint_sha256', 'valid_from', 'valid_to', 'p12_encrypted', 'password_encrypted', 'is_active', 'uploaded_by'];

    protected $hidden = ['p12_encrypted', 'password_encrypted'];

    protected function casts(): array
    {
        return [
            'p12_encrypted' => 'encrypted', 'password_encrypted' => 'encrypted',
            'valid_from' => 'datetime', 'valid_to' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->baseActivitylogOptions()->logExcept(['p12_encrypted', 'password_encrypted']);
    }

    public function isExpired(): bool
    {
        return $this->valid_to->isPast();
    }
}
