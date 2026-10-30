<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

beforeEach(fn () => Http::preventStrayRequests());

require __DIR__.'/Support/helpers.php';
require __DIR__.'/Support/crud.php';
require __DIR__.'/Support/spreadsheets.php';
