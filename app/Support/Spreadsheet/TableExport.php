<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** A one-sheet document: headings + one row per record, with an optional styling hook. */
abstract class TableExport extends SpreadsheetExport
{
    /** @return array<int, mixed>|array<int, array<int, mixed>> one heading row, or several */
    abstract protected function headings(): array;

    /** @return iterable<int, array<int, mixed>> */
    abstract protected function rows(): iterable;

    protected function title(): string
    {
        return 'Sheet1';
    }

    protected function autoSize(): bool
    {
        return true;
    }

    /** Extra formatting after the table is written. */
    protected function style(Worksheet $sheet): void {}

    public function spreadsheet(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($this->title());
        self::writeTable($sheet, $this->headings(), $this->rows(), $this->autoSize());
        $this->style($sheet);

        return $book;
    }
}
