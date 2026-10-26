<?php

namespace BookStack\Databases;

use InvalidArgumentException;

/** Exact fixed-scale arithmetic without requiring the optional BCMath extension. */
class DecimalValue
{
    public static function normalize(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?(0|[1-9]\d{0,17})(\.\d{1,6})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Use a decimal string with at most 18 integer and 6 fractional digits.');
        }

        return self::fromScaled(self::toScaled((string) $value));
    }

    /** @param list<string> $values */
    public static function aggregate(array $values, string $operation): ?string
    {
        if (!$values) {
            return $operation === 'sum' ? '0' : null;
        }

        $scaled = array_map(self::toScaled(...), $values);
        if ($operation === 'min' || $operation === 'max') {
            $result = array_shift($scaled);
            foreach ($scaled as $value) {
                $comparison = self::compare($value, $result);
                if (($operation === 'min' && $comparison < 0) || ($operation === 'max' && $comparison > 0)) {
                    $result = $value;
                }
            }

            return self::fromScaled($result);
        }

        $result = '0';
        foreach ($scaled as $value) {
            $result = self::signedAdd($result, $value);
        }
        if ($operation === 'average') {
            $negative = str_starts_with($result, '-');
            $remainder = 0;
            $quotient = '';
            foreach (str_split(ltrim($result, '-')) as $digit) {
                $current = $remainder * 10 + (int) $digit;
                $quotient .= (string) intdiv($current, count($values));
                $remainder = $current % count($values);
            }
            $quotient = self::unsigned($quotient);
            if ($remainder * 2 >= count($values)) {
                $quotient = self::add($quotient, '1');
            }
            $result = $negative && $quotient !== '0' ? '-' . $quotient : $quotient;
        }

        return self::fromScaled($result);
    }

    private static function toScaled(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $digits = self::unsigned($whole . str_pad($fraction, 6, '0'));

        return $negative && $digits !== '0' ? '-' . $digits : $digits;
    }

    private static function fromScaled(string $value): string
    {
        $digits = str_pad(ltrim($value, '-'), 7, '0', STR_PAD_LEFT);
        $whole = self::unsigned(substr($digits, 0, -6));
        $fraction = rtrim(substr($digits, -6), '0');
        $result = $whole . ($fraction !== '' ? '.' . $fraction : '');

        return str_starts_with($value, '-') && $result !== '0' ? '-' . $result : $result;
    }

    private static function unsigned(string $value): string
    {
        return ltrim($value, '0') ?: '0';
    }

    private static function compare(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $left = ltrim($left, '-');
        $right = ltrim($right, '-');
        $comparison = strlen($left) <=> strlen($right);
        $comparison = $comparison ?: strcmp($left, $right);

        return $leftNegative ? -$comparison : $comparison;
    }

    private static function signedAdd(string $left, string $right): string
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $a = ltrim($left, '-');
        $b = ltrim($right, '-');
        if ($leftNegative === $rightNegative) {
            $sum = self::add($a, $b);

            return $leftNegative && $sum !== '0' ? '-' . $sum : $sum;
        }

        $comparison = self::compare($a, $b);
        $difference = $comparison >= 0 ? self::subtract($a, $b) : self::subtract($b, $a);
        $negative = $comparison >= 0 ? $leftNegative : $rightNegative;

        return $negative && $difference !== '0' ? '-' . $difference : $difference;
    }

    private static function add(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return self::unsigned($result);
    }

    /** Subtract positive integer strings where left >= right. */
    private static function subtract(string $left, string $right): string
    {
        $result = '';
        $borrow = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0; $i--, $j--) {
            $digit = (int) $left[$i] - ($j >= 0 ? (int) $right[$j] : 0) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit) . $result;
        }

        return self::unsigned($result);
    }
}
