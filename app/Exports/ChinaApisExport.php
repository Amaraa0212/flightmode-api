<?php

namespace App\Exports;

use App\Enums\SegmentStatus;
use App\Models\Country;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Support\Spreadsheet\TableExport;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "List of Entry/Departure Flight and Staffs" register required by Chinese border control (APIS).
 * Layout copied from the legacy export; nationality / issuing country are converted to ISO alpha-3.
 */
class ChinaApisExport extends TableExport
{
    /** @var array<string, string> alpha2 => alpha3 */
    private array $alpha3;

    public function __construct(private readonly Flight $flight)
    {
        $flight->loadMissing(['airline', 'origin', 'destination', 'aircraft']);
        $this->alpha3 = Country::query()->pluck('alpha3', 'alpha2')->all();
    }

    protected function rows(): iterable
    {
        return PassengerSegment::query()->with('passenger')
            ->where('flight_id', $this->flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->get()
            ->map(fn (PassengerSegment $segment) => $this->row($segment));
    }

    protected function headings(): array
    {
        return [
            ['出入境飞机及员工登记表'],
            ['List of Entry/Departure Flight and Staffs Pattern and Register'],
            ['航班类型', '航班号', '', '公司名', '始发/目的国', '机型机号', '国籍', '出过境机组人数', '航班日期'],
            ['Type of Flight', 'Flight Number', '', 'Airline', 'Original/Destination Country', 'Registration of Aircraft', 'Nationality of Aircraft', 'Total Crew of Entry, Departure or transit', 'Flight Date'],
            ['', '', '', '', '', '', '', '', ''],
            ['证件号码No. of Passport', '飞行执照No. of Licence', '英文名First Name', '中文名Chinese Name', '国籍Nationality', '性别Sex', '出生日期Date of Birth', '职务Occupation', '护照有效期Passport Valid Until', '护照类型Type of Passport', '证件签发国Issuing Country', '出生国家Country of Birth'],
        ];
    }

    protected function autoSize(): bool
    {
        return false;
    }

    /** @return array<int, mixed> */
    private function row(PassengerSegment $segment): array
    {
        $p = $segment->passenger;

        return [
            $p->document_number,
            '',
            str_replace('-', '', (string) $p->last_name).'/'.str_replace('-', '', (string) $p->first_name),
            '',
            $this->alpha3($p->nationality),
            $p->gender?->value,
            $p->date_of_birth?->format('Y.m.d'),
            '',
            $p->document_expires_at?->format('Y.m.d'),
            'P',
            $this->alpha3($p->document_issuing_country),
            '',
        ];
    }

    /** Legacy layout: merged titles, borders, flight data in row 5, times below the table. */
    protected function style(Worksheet $sheet): void
    {
        $flight = $this->flight;
        $dep = $flight->scheduled_departure_at->copy()->timezone($flight->origin->timezone);
        $arr = $flight->scheduled_arrival_at->copy()->timezone($flight->destination->timezone);

        foreach (['A' => 16, 'B' => 19, 'C' => 30, 'D' => 20, 'E' => 20, 'F' => 13, 'G' => 14, 'H' => 20, 'I' => 20, 'J' => 15, 'K' => 15, 'L' => 15] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $center = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true];
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1:I1')->applyFromArray(['font' => ['bold' => true, 'size' => 16], 'alignment' => $center]);
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2:I2')->applyFromArray(['font' => ['bold' => true, 'size' => 14], 'alignment' => $center]);
        foreach (['A3:I3', 'A4:I4', 'A5:I5', 'A6:L6'] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
                'alignment' => $center,
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $sheet->setCellValue('B5', $flight->flight_number);
        $sheet->setCellValue('D5', $flight->airline->iata_code ?? $flight->airline->name);
        $sheet->setCellValue('E5', '('.$flight->origin->iata_code.')/('.$flight->destination->iata_code.')');
        $sheet->setCellValue('F5', $flight->aircraft?->registration);
        $sheet->setCellValue('I5', $dep->format('Y.m.d'));
        $sheet->getStyle('A6:L6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF00');

        $last = $sheet->getHighestRow();
        if ($last > 6) {
            $sheet->getStyle("A7:L{$last}")->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
        }

        $row = $last + 2;
        foreach ([['A', '预计起飞日期', $dep->format('Y.m.d')], ['B', '预计起飞时间', $dep->format('H:i:s')],
            ['C', '预计到达日期', $arr->format('Y.m.d')], ['D', '预计到达时间', $arr->format('H:i:s')]] as [$col, $label, $value]) {
            $sheet->setCellValue($col.$row, $label);
            $sheet->setCellValue($col.($row + 1), $value);
        }
        $sheet->setCellValue('F'.$row, '飞行类型');
        $sheet->setCellValue('G'.$row, 'N');
        $sheet->setCellValue('I'.$row, 'N:Normal');
        $sheet->setCellValue('I'.($row + 1), 'T:Transit');
        $sheet->setCellValue('I'.($row + 2), 'X:Transfer');
    }

    private function alpha3(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        $code = strtoupper($code);

        return strlen($code) === 2 ? ($this->alpha3[$code] ?? $code) : $code;
    }
}
