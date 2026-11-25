<?php

namespace App\Support\Spreadsheet;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Oyu Tolgoi's transport report (e.g. "OTUB 2140 E-195 WED 2026-10-28"): a title block, then a heading row with
 * No | Passenger Name | Company | Department | Position | Cost Center | Signatures | C No | Pick-up Address |
 * Profile SAP | Profile Personal Mobile, then one passenger per line.
 *
 * Read like the legacy import (PassengerExcelImport): "Mr. Aldarjavkhlan Bold" → title Mr, first name ALDARJAVKHLAN,
 * last name BOLD; Profile SAP is the person code used for online check-in. The heading row is found by its titles,
 * so a few more or fewer title lines do not matter.
 */
final class OtTransportReport
{
    private const NAME = 'passenger_name';

    private const SAP = 'profile_sap';

    private const MOBILE = 'profile_personal_mobile';

    /** Titles that tell the gender; any other title (Dr …) leaves it empty */
    private const TITLES = ['MR' => 'M', 'MSTR' => 'M', 'MRS' => 'F', 'MS' => 'F', 'MISS' => 'F', 'DR' => null];

    /**
     * Passengers keyed by the spreadsheet line number.
     *
     * @return Collection<int, array{name: string, first_name: ?string, last_name: ?string, gender: ?string, person_code: ?string, phone: ?string}>
     */
    public static function passengers(UploadedFile $file): Collection
    {
        $cells = SpreadsheetReader::cells($file);

        $columns = null;
        $headingLine = null;
        foreach ($cells as $line => $values) {
            $slugs = array_map(fn ($v) => SpreadsheetReader::slug($v), $values);
            if (in_array(self::NAME, $slugs, true) && in_array(self::SAP, $slugs, true)) {
                $columns = array_flip(array_filter($slugs));
                $headingLine = $line;
                break;
            }
        }

        if ($columns === null) {
            throw BusinessRuleException::make('import_not_ot_report',
                'This is not an Oyu Tolgoi transport report: no heading row with "Passenger Name" and "Profile SAP".');
        }

        $passengers = collect();
        foreach ($cells as $line => $values) {
            if ($line <= $headingLine) {
                continue;
            }
            $name = trim((string) ($values[$columns[self::NAME]] ?? ''));
            if ($name === '') {
                continue;
            }

            $passengers->put($line, self::splitName($name) + [
                'name' => $name,
                'person_code' => self::text($values[$columns[self::SAP]] ?? null),
                'phone' => self::phone($values[$columns[self::MOBILE] ?? -1] ?? null),
            ]);
        }

        return $passengers;
    }

    /** @return array{first_name: ?string, last_name: ?string, gender: ?string} */
    private static function splitName(string $name): array
    {
        $words = preg_split('/\s+/', $name) ?: [];
        $title = mb_strtoupper(rtrim($words[0] ?? '', '.'));
        $gender = null;
        if (array_key_exists($title, self::TITLES)) {
            $gender = self::TITLES[$title];
            array_shift($words);
        }

        return [
            'first_name' => isset($words[0]) ? mb_strtoupper($words[0]) : null,
            'last_name' => count($words) > 1 ? mb_strtoupper(implode(' ', array_slice($words, 1))) : null,
            'gender' => $gender,
        ];
    }

    /** SAP numbers may come back as numbers (9592220.0) */
    private static function text(mixed $value): ?string
    {
        $text = trim(is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value);

        return $text === '' ? null : mb_strtoupper($text);
    }

    private static function phone(mixed $value): ?string
    {
        $phone = ltrim(trim((string) $value), "='\"");
        if ($phone === '') {
            return null;
        }

        return str_starts_with($phone, '+') ? '+'.preg_replace('/\D+/', '', substr($phone, 1)) : preg_replace('/\D+/', '', $phone);
    }
}
