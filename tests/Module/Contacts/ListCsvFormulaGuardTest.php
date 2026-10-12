<?php

declare(strict_types=1);

namespace App\Tests\Module\Contacts;

use App\Module\Contacts\Application\ListCsvFormulaGuard;
use PHPUnit\Framework\TestCase;

final class ListCsvFormulaGuardTest extends TestCase
{
    public function testFormulaLeadingEmailAndNamesRoundTripWithoutAmbiguity(): void
    {
        foreach (['+tag@example.test', '-tag@example.test', '=tag@example.test', '@name', "\tName", "\rName", "\nName"] as $value) {
            $protected = ListCsvFormulaGuard::protect($value);
            self::assertNotSame($value, $protected);
            self::assertStringStartsWith("'EMAILER_CSV_SAFE_V1:", $protected);
            self::assertSame($value, ListCsvFormulaGuard::restore($protected));
        }
        foreach (["'csv:+tag@example.test", "'EMAILER_CSV_SAFE_V1:ordinary@example.test", 'José, Ana', 'plain@example.test'] as $value) {
            self::assertSame($value, ListCsvFormulaGuard::restore($value));
        }
        foreach (["'EMAILER_CSV_SAFE_V1:=Formula", "'EMAILER_CSV_SAFE_V1:ordinary", "'EMAILER_CSV_SAFE_V1:'EMAILER_CSV_SAFE_V1:@Nested"] as $value) {
            $protected = ListCsvFormulaGuard::protect($value);
            self::assertNotSame($value, $protected);
            self::assertSame($value, ListCsvFormulaGuard::restore($protected));
        }
    }
}
