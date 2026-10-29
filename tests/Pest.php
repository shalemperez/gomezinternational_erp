<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

require __DIR__.'/Support/helpers.php';
require __DIR__.'/Support/crud.php';
