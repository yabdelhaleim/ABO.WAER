<?php

namespace App\Services;

/**
 * Escapes a value for a spreadsheet.
 *
 * A CSV file is opened by a spreadsheet, not by a database, and a
 * spreadsheet treats a cell that starts with "=", "+", "-" or "@" as a
 * formula rather than as text. A product called
 *
 *     =1+1 | cmd | ' /c calc'!A1
 *
 * would therefore run as a formula on the machine of whoever opens the
 * export — which is how a "harmless" download of the catalogue turns
 * into somebody else's command.
 *
 * The fix is the one the spreadsheet vendors recommend: a cell that
 * could be read as a formula is prefixed with a single quote, which
 * marks it as text and leaves what it says untouched.
 *
 * Numbers are left alone. A cell that is a number is never a formula,
 * and the export's numeric columns have to stay numeric so the file can
 * be summed in a spreadsheet.
 */
class CsvExportService
{
    /**
     * The characters a spreadsheet may read as the start of a formula.
     *
     * @var array<int, string>
     */
    public const array DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Make one value safe to put in a cell.
     */
    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) $value;

        if ($text === '') {
            return '';
        }

        if (is_numeric($text)) {
            return $text;
        }

        // Leading spaces do not stop a spreadsheet from reading a cell
        // as a formula, so the check looks past them — the quote still
        // goes in front, where it does its work.
        $trimmed = ltrim($text, " \t\r\n");

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return "'".$text;
            }
        }

        return $text;
    }

    /**
     * Make a whole row safe to put in the file.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function row(array $values): array
    {
        return array_map(
            fn (mixed $value): string => self::escape($value),
            array_values($values),
        );
    }
}
