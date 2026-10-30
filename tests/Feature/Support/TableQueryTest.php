<?php

use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Http\Request;

test('search filters with ilike across columns and paginates', function () {
    User::factory()->create(['name' => 'María Gómez', 'email' => 'maria@x.test']);
    User::factory()->create(['name' => 'Pedro', 'email' => 'pedro@x.test']);

    $result = TableQuery::apply(User::query(), Request::create('/', 'GET', ['search' => 'maría', 'per_page' => '25']), ['name', 'email'], ['name'], 'name', 'asc');

    expect($result->total())->toBe(1)->and($result->perPage())->toBe(25)->and($result->first()->name)->toBe('María Gómez');
});

test('unknown sort column falls back to default and per_page is whitelisted', function () {
    User::factory()->create(['name' => 'B']);
    User::factory()->create(['name' => 'A']);

    $result = TableQuery::apply(User::query(), Request::create('/', 'GET', ['sort' => 'password', 'dir' => 'asc', 'per_page' => '7']), ['name'], ['name'], 'name', 'asc');

    expect($result->perPage())->toBe(15)->and($result->first()->name)->toBe('A');
});
