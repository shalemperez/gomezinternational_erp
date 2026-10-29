<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use TypeError;

final class Decimal
{
    public const MONEY = 2;

    public const QTY = 4;

    public const RATE = 6;

    /** @param mixed $value string|int|BigDecimal, never a float */
    public static function of(mixed $value): BigDecimal
    {
        if (is_float($value)) {
            throw new TypeError('Decimal::of no acepta float');
        }
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new TypeError('Decimal::of acepta string, int o BigDecimal');
        }

        return BigDecimal::of($value);
    }

    /** @param int<0, max> $scale */
    public static function scale(string|int|BigDecimal $value, int $scale): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function money(string|int|BigDecimal $value): string
    {
        return self::scale($value, self::MONEY);
    }

    public static function qty(string|int|BigDecimal $value): string
    {
        return self::scale($value, self::QTY);
    }

    public static function rate(string|int|BigDecimal $value): string
    {
        return self::scale($value, self::RATE);
    }

    /** @param int<0, max> $scale */
    public static function mul(string|BigDecimal $a, string|BigDecimal $b, int $scale): string
    {
        return self::scale(self::of($a)->multipliedBy(self::of($b)), $scale);
    }

    /** @param int<0, max> $scale */
    public static function div(string|BigDecimal $a, string|BigDecimal $b, int $scale): string
    {
        return (string) self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HalfUp);
    }

    /** @param int<0, max> $scale */
    public static function percent(string|BigDecimal $base, string|BigDecimal $pct, int $scale = self::MONEY): string
    {
        return (string) self::of($base)->multipliedBy(self::of($pct))->dividedBy(100, $scale, RoundingMode::HalfUp);
    }

    /**
     * @param  iterable<string|int|BigDecimal>  $values
     * @param  int<0, max>  $scale
     */
    public static function sum(iterable $values, int $scale = self::MONEY): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::of($v));
        }

        return self::scale($total, $scale);
    }

    public static function isZero(string|BigDecimal $value): bool
    {
        return self::of($value)->isZero();
    }

    public static function cmp(string|BigDecimal $a, string|BigDecimal $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }
}
