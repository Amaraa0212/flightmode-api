<?php

namespace App\Services\Booking;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Enums\PassengerType;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Booking;
use App\Models\User;
use App\Support\PassengerAge;
use App\Support\Spreadsheet\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Bulk passenger upload for an allocation (one booking per row, as in the legacy import).
 * Every row is checked first; nothing is saved when any row is wrong.
 *
 * Columns: firstname, lastname, sex, dob, nationality, passport_number, issued_state, expire_date,
 *          email, phone, person_code, cabin (default Y), agent_contact
 */
class PassengerImportService
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    /** @return array{created: int, bookings: array<int, string>} */
    public function import(UploadedFile $file, AgencyAllocation $allocation, User $actor, bool $replaceExisting = false): array
    {
        $rows = SpreadsheetReader::rows($file);

        $passengers = [];
        $errors = [];
        $departure = $allocation->outboundFlight->departureAt();

        foreach ($rows as $line => $row) {
            $data = [
                'first_name' => $row->get('firstname'),
                'last_name' => $row->get('lastname'),
                'gender' => strtoupper((string) $row->get('sex')) ?: null,
                'date_of_birth' => $this->date($row->get('dob')),
                'nationality' => $row->get('nationality'),
                'document_type' => 'passport',
                'document_number' => $row->get('passport_number'),
                'document_issuing_country' => strtoupper(trim((string) $row->get('issued_state'))) ?: null,
                'document_expires_at' => $this->date($row->get('expire_date')),
                'email' => $row->get('email'),
                'phone' => $this->phone($row->get('phone')),
                'person_code' => $row->get('person_code'),
                'cabin_class' => strtoupper((string) ($row->get('cabin') ?: 'Y')),
            ];

            if (! $data['first_name'] || ! $data['last_name']) {
                $errors[] = "Row {$line}: first and last name are required.";

                continue;
            }
            if (! preg_match('/^[A-Z]{2}$/', (string) $data['document_issuing_country'])) {
                $errors[] = "Row {$line}: issued_state must be a 2-letter country code.";
            }
            if ($data['date_of_birth'] && PassengerAge::typeOn(Carbon::parse($data['date_of_birth']), $departure) === PassengerType::Infant) {
                $errors[] = "Row {$line}: infants cannot be imported, add them to their adult.";
            }
            if (! in_array($data['cabin_class'], ['F', 'C', 'W', 'Y'], true)) {
                $errors[] = "Row {$line}: cabin must be F, C, W or Y.";
            }

            $passengers[] = ['line' => $line, 'data' => $data, 'contact_phone' => $row->get('agent_contact')];
        }

        if ($passengers === []) {
            throw BusinessRuleException::make('import_empty', 'The file has no passenger rows.');
        }
        if ($errors !== []) {
            throw BusinessRuleException::make('import_invalid', 'The file has errors; nothing was imported.', ['errors' => $errors]);
        }

        return DB::transaction(function () use ($passengers, $allocation, $actor, $replaceExisting) {
            if ($replaceExisting) {
                $this->cancelExisting($allocation);
            }

            $pnrs = [];
            foreach ($passengers as $row) {
                try {
                    $booking = $this->bookings->create([
                        'allocation_id' => $allocation->id,
                        'contact_phone' => $row['contact_phone'],
                        'passengers' => [$row['data']],
                    ], $actor, BookingSource::Import);
                } catch (BusinessRuleException $e) {
                    throw BusinessRuleException::make($e->errorCode, "Row {$row['line']}: ".$e->getMessage(), $e->context);
                }
                $pnrs[] = $booking->pnr;
            }

            return ['created' => count($pnrs), 'bookings' => $pnrs];
        });
    }

    private function cancelExisting(AgencyAllocation $allocation): void
    {
        Booking::query()->with('passengers')->where('agency_allocation_id', $allocation->id)->get()
            ->flatMap->passengers
            ->filter(fn ($p) => $p->infant_of_passenger_id === null)
            ->each(fn ($p) => $this->bookings->cancelPassenger($p, ActivityChannel::Agency, 'Replaced by import'));
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject($value))->toDateString()
                : Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function phone(mixed $value): ?string
    {
        $phone = ltrim(trim((string) $value), "='\"");
        if ($phone === '') {
            return null;
        }

        return str_starts_with($phone, '+') ? '+'.preg_replace('/\D+/', '', substr($phone, 1)) : preg_replace('/\D+/', '', $phone);
    }
}
