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

        $headings = array_map(fn ($h) => Str::slug(trim((string) $h), '_'), array_shift($data) ?? []);

        $rows = collect();
        foreach ($data as $index => $values) {
            $row = collect($headings)->filter()->mapWithKeys(fn ($heading, $column) => [$heading => $values[$column] ?? null]);
            if ($row->contains(fn ($v) => $v !== null && $v !== '')) {
                $rows->put($index + 2, $row); // line 1 is the heading row
            }
        }

        return $rows;
    }
}
