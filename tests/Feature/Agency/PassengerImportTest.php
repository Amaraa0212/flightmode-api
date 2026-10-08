<?php

namespace Tests\Feature\Agency;

use App\Models\Passenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class PassengerImportTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private const HEADINGS = ['FirstName', 'LastName', 'Sex', 'DOB', 'Nationality', 'Passport Number', 'Issued State',
        'Expire Date', 'Email', 'Phone', 'Cabin', 'Agent Contact'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([self::HEADINGS, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'passengers.xlsx', null, null, true);
    }

    public function test_agency_imports_passengers_from_xlsx(): void
    {
        $allocation = $this->makeAllocation($this->makeFlight());
        $this->actingAsRole('agency-agent');
        $expires = ExcelDate::PHPToExcel(now()->addYears(3)->startOfDay());

        $file = $this->xlsx([
            ['Bat', 'Dorj', 'm', ExcelDate::PHPToExcel(new \DateTime('1990-05-01')), 'MN', 'E1111111', 'mn', $expires, 'bat@example.com', '99112233', 'Y', '88001122'],
            [null, null, null, null, null, null, null, null, null, null, null, null], // empty row is skipped
            ['Saraa', 'Bold', 'F', '1992-03-04', 'MN', 'E2222222', 'MN', now()->addYears(2)->toDateString(), null, "'99001122", null, null],
        ]);

        $this->post("/api/v1/agency/allocations/{$allocation->id}/import", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.created', 2);

        $bat = Passenger::query()->where('document_number', 'E1111111')->sole();
        $this->assertSame('1990-05-01', $bat->date_of_birth->toDateString());
        $this->assertSame('BAT', $bat->first_name);
        $this->assertSame('import', $bat->booking->source->value);
        $this->assertSame('1992-03-04', Passenger::query()->where('document_number', 'E2222222')->sole()->date_of_birth->toDateString());
    }

    public function test_invalid_rows_reject_the_whole_file_with_line_numbers(): void
    {
        $allocation = $this->makeAllocation($this->makeFlight());
        $this->actingAsRole('agency-agent');

        $file = $this->xlsx([
            ['Bat', 'Dorj', 'M', '1990-05-01', 'MN', 'E1111111', 'MN', now()->addYears(3)->toDateString(), null, null, 'Y', null],
            ['Saraa', 'Bold', 'F', '1992-03-04', 'MN', 'E2222222', 'MNG', now()->addYears(3)->toDateString(), null, null, 'Q', null],
        ]);

        $this->post("/api/v1/agency/allocations/{$allocation->id}/import", ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'import_invalid')
            ->assertJsonPath('context.errors.0', 'Row 3: issued_state must be a 2-letter country code.')
            ->assertJsonPath('context.errors.1', 'Row 3: cabin must be F, C, W or Y.');

        $this->assertSame(0, Passenger::query()->count());
    }
}
