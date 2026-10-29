<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\CausesActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use CausesActivity, HasFactory, HasRoles, LogsModelActivity, Notifiable;

    protected $fillable = ['name', 'email', 'google_id', 'avatar_url', 'is_active', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
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
