<?php

namespace Tests\Databases;

use BookStack\Databases\DecimalValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalValueTest extends TestCase
{
    #[DataProvider('normalizationExamples')]
    public function test_decimal_values_are_canonical_and_exact(mixed $input, string $expected): void
    {
        $this->assertSame($expected, DecimalValue::normalize($input));
    }

    public static function normalizationExamples(): array
    {
        return [
            ['0', '0'], ['-0.000000', '0'], [42, '42'], ['-42.230000', '-42.23'],
            ['9007199254740993.123456', '9007199254740993.123456'],
            ['999999999999999999.999999', '999999999999999999.999999'],
            ['0.000001', '0.000001'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_ambiguous_out_of_range_and_inexact_inputs_are_rejected(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalValue::normalize($input);
    }

    public static function invalidNumbers(): array
    {
        return [
            [0.1], [true], [null], [[]], ['1e3'], ['+1'], ['01'], ['1,000'], [' 1'], ['1 '],
            ['1.0000001'], ['1000000000000000000'], ['NaN'], ['1.'], ['.1'], ["1\n"],
        ];
    }

    #[DataProvider('aggregateExamples')]
    public function test_aggregates_keep_precision_and_handle_signs_and_rounding(array $values, string $operation, ?string $expected): void
    {
        $this->assertSame($expected, DecimalValue::aggregate($values, $operation));
    }

    public static function aggregateExamples(): array
    {
        return [
            [['0.1', '0.2'], 'sum', '0.3'],
            [['999999999999999999.999999', '0.000001'], 'sum', '1000000000000000000'],
            [['-999999999999999999.999999', '-0.000001'], 'sum', '-1000000000000000000'],
            [['-5.25', '10.5', '-5.25'], 'sum', '0'],
            [['-10', '2'], 'min', '-10'], [['-10', '2'], 'max', '2'],
            [['-10', '-2'], 'min', '-10'], [['-10', '-2'], 'max', '-2'],
            [['9', '10'], 'min', '9'], [['9', '10'], 'max', '10'],
            [['0.1', '0.2'], 'average', '0.15'],
            [['0', '0.000001'], 'average', '0.000001'],
            [['0', '-0.000001'], 'average', '-0.000001'],
            [['1', '0', '0'], 'average', '0.333333'],
            [[], 'sum', '0'], [[], 'average', null], [[], 'min', null], [[], 'max', null],
        ];
    }
}
