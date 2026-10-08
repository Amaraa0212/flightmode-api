<?php

namespace App\Support\Spreadsheet;

use Illuminate\Http\Response;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Base class of every Excel / CSV document (built directly on PhpSpreadsheet).
 * A subclass only describes the workbook in spreadsheet(); download() and raw() are shared.
 */
abstract class SpreadsheetExport
{
    public const XLSX = 'xlsx';

    public const CSV = 'csv';

    private const MIME = [
        self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        self::CSV => 'text/csv; charset=UTF-8',
    ];

    abstract public function spreadsheet(): Spreadsheet;

    public function download(string $filename, string $format = self::XLSX): Response
    {
        return response($this->raw($format), 200, [
            'Content-Type' => self::mime($format),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** File contents, e.g. for an e-mail attachment. */
    public function raw(string $format = self::XLSX): string
    {
        $book = $this->spreadsheet();
        $writer = IOFactory::createWriter($book, match ($format) {
            self::XLSX => 'Xlsx',
            self::CSV => 'Csv',
            default => throw new InvalidArgumentException("Unsupported format {$format}."),
        });

        ob_start();
        $writer->save('php://output');
        $book->disconnectWorksheets();

        return (string) ob_get_clean();
    }

    public static function mime(string $format): string
    {
        return self::MIME[$format] ?? throw new InvalidArgumentException("Unsupported format {$format}.");
    }

    /**
     * Write heading row(s) and data rows starting at A1.
     *
     * @param  array<int, mixed>|array<int, array<int, mixed>>  $headings  one row, or several rows
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected static function writeTable(Worksheet $sheet, array $headings, iterable $rows, bool $autoSize = true): void
    {
        $headingRows = $headings !== [] && is_array(reset($headings)) ? $headings : ($headings === [] ? [] : [$headings]);

        $line = 1;
        foreach ($headingRows as $heading) {
            $sheet->fromArray(array_values($heading), null, 'A'.$line++, true);
        }
        if ($headingRows !== []) {
            $sheet->getStyle('A'.count($headingRows).':'.$sheet->getHighestColumn().count($headingRows))->getFont()->setBold(true);
        }

        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, 'A'.$line++, true);
        }

        if ($autoSize) {
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            }
        }
    }
}
