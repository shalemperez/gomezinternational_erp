<?php

namespace App\Support;

final readonly class FetchedRate
{
    /** @param array<string, mixed> $raw */
    public function __construct(public string $rate, public array $raw = []) {}
}
