<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class Settings
{
    private const CACHE_KEY = 'settings.all';

    /** @return array<string, mixed> */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->map(fn ($v) => $v['v'] ?? null)->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $all = $this->all();

        return array_combine($keys, array_map(fn ($k) => $all[$k] ?? null, $keys));
    }
}
