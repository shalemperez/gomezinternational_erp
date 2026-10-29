<?php

namespace Database\Seeders;

use App\Actions\Admin\SyncSuperAdmins;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(SyncSuperAdmins::class)->handle();
    }
}
