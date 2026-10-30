<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Str;

class SyncSuperAdmins
{
    /** Ensures every email in config('erp.super_admin_emails') exists as an active super-admin. Returns the number of users created. */
    public function handle(): int
    {
        $created = 0;
        foreach (config('erp.super_admin_emails', []) as $email) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user === null) {
                $user = User::create(['name' => Str::before($email, '@'), 'email' => $email, 'is_active' => true]);
                $created++;
            }
            if (! $user->hasRole(PermissionCatalog::SUPER_ADMIN)) {
                $user->assignRole(PermissionCatalog::SUPER_ADMIN);
            }
        }

        return $created;
    }
}
