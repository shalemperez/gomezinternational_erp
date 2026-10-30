<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use CausesActivity, HasFactory, HasRoles, LogsModelActivity, Notifiable {
        LogsModelActivity::getActivitylogOptions as protected baseActivitylogOptions;
    }

    protected $fillable = ['name', 'email', 'google_id', 'avatar_url', 'is_active', 'last_login_at', 'gmail_refresh_token', 'gmail_email', 'gmail_connected_at'];

    protected $hidden = ['password', 'remember_token', 'gmail_refresh_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'gmail_refresh_token' => 'encrypted',
            'gmail_connected_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->baseActivitylogOptions()->logExcept(['password', 'remember_token', 'gmail_refresh_token']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /** @return HasOne<Salesperson, $this> */
    public function salesperson(): HasOne
    {
        return $this->hasOne(Salesperson::class);
    }
}
