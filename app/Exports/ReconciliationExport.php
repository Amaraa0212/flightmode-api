<?php

namespace App\Exports;

use App\Support\Spreadsheet\TableExport;

/** PNL reconciliation (PassengerReconciliation): one row per passenger with flown / no-show / go-show. */
class ReconciliationExport extends TableExport
{
    private const LABELS = [
        'flown' => 'FLOWN',
        'no_show' => 'NO-SHOW',
        'offloaded' => 'OFFLOADED',
        'not_boarded' => 'NOT BOARDED',
        'cancelled' => 'CANCELLED',
        'waitlisted' => 'WAITLIST',
    ];

    /** @param array{rows: list<array<string, mixed>>} $document */
    public function __construct(private readonly array $document) {}

    protected function title(): string
    {
        return 'Reconciliation';
    }

    protected function headings(): array
    {
        return ['Result', 'Go-show', 'Name', 'Type', 'PNR', 'Ticket', 'Cabin', 'Seat', 'Agency', 'Offload reason'];
    }

    protected function rows(): iterable
    {
        foreach ($this->document['rows'] as $row) {
            yield [
                self::LABELS[$row['result']],
                $row['go_show'] ? 'GO-SHOW' : '',
                $row['name'],
                $row['type'],
                $row['pnr'],
                $row['ticket_number'],
                $row['cabin_class'],
                $row['seat_number'],
                $row['agency'],
                trim(($row['offload_type'] ?? '').' '.($row['offload_reason'] ?? '')),
            ];
        }
    }
}
