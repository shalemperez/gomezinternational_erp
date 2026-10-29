<?php

namespace App\Support;

final class FiscalId
{
    private const RNC_WEIGHTS = [7, 9, 8, 6, 5, 4, 3, 2];

    public static function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** True when the input contains nothing but digits, spaces and dashes. */
    private static function onlySeparators(string $value): bool
    {
        return preg_match('/^[\d\s-]*$/', $value) === 1;
    }

    public static function rncCheckDigit(string $firstEight): int
    {
        $sum = 0;
        foreach (self::RNC_WEIGHTS as $i => $w) {
            $sum += $w * (int) $firstEight[$i];
        }

        return (10 - ($sum % 11)) % 9 + 1;
    }

    public static function isValidRnc(string $value): bool
    {
        $n = self::normalize($value);
        if (! self::onlySeparators($value) || strlen($n) !== 9) {
            return false;
        }

        return self::rncCheckDigit(substr($n, 0, 8)) === (int) $n[8];
    }

    public static function isValidCedula(string $value): bool
    {
        $n = self::normalize($value);
        if (! self::onlySeparators($value) || strlen($n) !== 11) {
            return false;
        }

        return self::luhnChecksum($n) === 0;
    }

    /** Luhn: from the rightmost digit, double every second digit, subtract 9 when > 9, sum; valid when sum mod 10 == 0. */
    private static function luhnChecksum(string $digits): int
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10;
    }

    public static function format(string $value): string
    {
        $n = self::normalize($value);

        return match (strlen($n)) {
            9 => substr($n, 0, 1).'-'.substr($n, 1, 2).'-'.substr($n, 3, 5).'-'.substr($n, 8, 1),
            11 => substr($n, 0, 3).'-'.substr($n, 3, 7).'-'.substr($n, 10, 1),
            default => $n,
        };
    }
}
