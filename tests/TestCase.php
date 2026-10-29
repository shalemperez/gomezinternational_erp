<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed roles and permissions once, when RefreshDatabase migrates; each test then runs in a rolled-back transaction. */
    protected bool $seed = true;

    protected string $seeder = PermissionSeeder::class;
}
