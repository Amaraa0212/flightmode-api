<?php

namespace App\Support\Spreadsheet;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/** Reads the first sheet of an uploaded xlsx / xls / csv into rows keyed by the heading row. */
final class SpreadsheetReader
{
    private const READERS = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv' => 'Csv'];

    /**
     * Headings are normalised to snake_case slugs ("Passport Number" → passport_number).
     * Values are raw (dates stay Excel serial numbers). Empty rows are skipped.
     *
     * @return Collection<int, Collection<string, mixed>> keyed by the spreadsheet line number
     */
    public static function rows(UploadedFile $file): Collection
    {
        $data = self::cells($file);
        // line 1 is the heading row (unset, not array_shift: the keys stay line numbers)
        $headings = array_map(fn ($h) => self::slug($h), $data[1] ?? []);
        unset($data[1]);

        $rows = collect();
        foreach ($data as $line => $values) {
            $row = collect($headings)->filter()->mapWithKeys(fn ($heading, $column) => [$heading => $values[$column] ?? null]);
            if ($row->contains(fn ($v) => $v !== null && $v !== '')) {
                $rows->put($line, $row);
            }
        }

        return $rows;
    }

    /**
     * Every cell of the first sheet, for layouts whose heading row is not the first line.
     *
     * @return array<int, array<int, mixed>> line number (1-based) => cell values by column (0-based)
     */
    public static function cells(UploadedFile $file): array
    {
        $type = self::READERS[strtolower($file->getClientOriginalExtension())] ?? null;
        if (! $type) {
            throw BusinessRuleException::make('import_unsupported_file', 'Upload an xlsx, xls or csv file.');
        }

        try {
            $book = IOFactory::createReader($type)->setReadDataOnly(true)->load($file->getRealPath());
        } catch (Throwable) {
            throw BusinessRuleException::make('import_unreadable_file', 'The file could not be read.');
        }

        $data = $book->getSheet(0)->toArray(null, true, false, false);
        $book->disconnectWorksheets();

        return array_combine(range(1, max(1, count($data))), $data ?: [[]]);
    }

    /** "Profile SAP" → profile_sap */
    public static function slug(mixed $heading): string
    {
        return Str::slug(trim((string) $heading), '_');
    }
}
