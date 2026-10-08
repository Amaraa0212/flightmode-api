<?php

namespace App\Exports;

use App\Support\Spreadsheet\SpreadsheetExport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Passenger, baggage and balance manifests of a flight, one sheet each (data from ManifestService::build). */
class ManifestExport extends SpreadsheetExport
{
    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document) {}

    public function spreadsheet(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        if (isset($this->document['passengers'])) {
            $t = $this->document['passengers']['totals'];
            $this->sheet($book, 'Passengers', ['Name', 'Gender', 'Type', 'PNR', 'Cabin', 'Seat', 'SSR', 'Bags', 'KG'],
                array_merge(array_map(fn ($r) => [$r['name'], $r['gender'], $r['type'], $r['pnr'], $r['cabin_class'],
                    $r['seat_number'], $r['ssr'], $r['bag_pieces'], $r['bag_weight_kg']], $this->document['passengers']['rows']),
                    [['', '', '', '', '', 'Total', "ADT {$t['ADT']}, CHD {$t['CHD']}, INF {$t['INF']}"]]));
        }

        if (isset($this->document['baggage'])) {
            $this->sheet($book, 'Baggage', ['Passenger', 'Seat', 'Class', 'Pieces', 'KG', 'Tags', 'Remark'],
                array_map(fn ($r) => [$r['passenger'], $r['seat_number'], $r['cabin_class'], $r['pieces'], $r['weight_kg'],
                    implode(', ', $r['tags']), $r['description']], $this->document['baggage']['rows']));
        }

        if (isset($this->document['balance'])) {
            $t = $this->document['balance']['totals'];
            $this->sheet($book, 'Balance', ['Zone', 'M', 'F', 'CHD', 'INF', 'PAX'],
                array_merge(array_map(fn ($r) => [$r['zone'], $r['male_adults'], $r['female_adults'], $r['children'], $r['infants'], $r['total']],
                    $this->document['balance']['rows']),
                    [['Total', $t['male_adults'], $t['female_adults'], $t['children'], $t['infants'], $t['total']]]));
        }

        if ($book->getSheetCount() === 0) {
            $book->createSheet()->setTitle('Manifest');
        }

        return $book;
    }

    /**
     * @param  array<string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function sheet(Spreadsheet $book, string $title, array $headings, array $rows): void
    {
        self::writeTable($book->createSheet()->setTitle($title), $headings, $rows);
    }
}
