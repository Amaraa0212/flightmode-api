<?php

namespace App\Services\Booking;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\User;
use App\Services\Allocation\AllocationService;
use App\Support\PassengerAge;
use App\Support\Spreadsheet\OtTransportReport;
use App\Support\Spreadsheet\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Bulk passenger upload for an allocation: one booking per row, as in the legacy import, unless rows share a
 * booking_group — those become one booking (one PNR). Every row is checked first; nothing is saved when any row is wrong.
 *
 * Columns: firstname, lastname, sex, dob, nationality, passport_number, issued_state, expire_date,
 *          email, phone, person_code, cabin (default Y), agent_contact, booking_group
 * Agencies set to the Oyu Tolgoi layout upload OT's transport report instead (importOtReport).
 */
class PassengerImportService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AllocationService $allocations,
    ) {}

    /** @return array{created: int, bookings: array<int, string>, kept?: int, cancelled?: int, waitlisted?: int} */
    public function import(UploadedFile $file, AgencyAllocation $allocation, User $actor, bool $replaceExisting = false, ?AgencyAllocation $return = null): array
    {
        if ($allocation->agency?->importsOtReport()) {
            return $this->importOtReport($file, $allocation, $actor, $replaceExisting, $return);
        }

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
            if (! preg_match('/^[A-Za-z][A-Za-z \'-]*$/', trim($data['first_name'].' '.$data['last_name']))) {
                $errors[] = "Row {$line}: first and last name must be written in Latin letters (no Cyrillic).";
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

            $group = trim((string) $row->get('booking_group'));
            $passengers[] = ['line' => $line, 'data' => $data, 'contact_phone' => $row->get('agent_contact'),
                // rows without a group: a booking each
                'group' => $group !== '' ? 'g:'.mb_strtoupper($group) : 'row:'.$line];
        }

        if ($passengers === []) {
            throw BusinessRuleException::make('import_empty', 'The file has no passenger rows.');
        }
        if ($errors !== []) {
            throw BusinessRuleException::make('import_invalid', 'The file has errors; nothing was imported.', ['errors' => $errors]);
        }

        return DB::transaction(function () use ($passengers, $allocation, $actor, $replaceExisting, $return) {
            if ($replaceExisting) {
                $this->cancelExisting($allocation);
            }

            $pnrs = [];
            $created = 0;
            foreach (collect($passengers)->groupBy('group') as $rows) {
                $lines = $rows->pluck('line')->implode(', ');
                try {
                    $booking = $this->bookings->create([
                        'allocation_id' => $allocation->id,
                        // RT: the same people back (two linked bookings when it is another airline's)
                        'return_allocation_id' => $return?->id,
                        'contact_phone' => $rows->pluck('contact_phone')->filter()->first(),
                        'passengers' => $rows->pluck('data')->all(),
                    ], $actor, BookingSource::Import);
                } catch (BusinessRuleException $e) {
                    throw BusinessRuleException::make($e->errorCode, ($rows->count() > 1 ? "Rows {$lines}: " : "Row {$lines}: ").$e->getMessage(), $e->context);
                }
                $pnrs[] = $booking->relationLoaded('linkedBooking') && $booking->linkedBooking
                    ? "{$booking->pnr} / {$booking->linkedBooking->pnr}" : $booking->pnr;
                $created += $rows->count();
            }

            return ['created' => $created, 'bookings' => $pnrs];
        });
    }

    /**
     * Oyu Tolgoi transport report (agencies with import_format = ot): one booking per worker. OT sends the whole list
     * again when it changes, so a worker already on the allocation (same SAP number, or same name without one) is
     * kept as is — seat and check-in stay. With $sync the workers no longer in the file are cancelled.
     * Past the allocation's seats the bookings are waitlisted, in file order.
     *
     * @return array{created: int, kept: int, cancelled: int, waitlisted: int, bookings: array<int, string>}
     */
    private function importOtReport(UploadedFile $file, AgencyAllocation $allocation, User $actor, bool $sync, ?AgencyAllocation $return): array
    {
        $rows = OtTransportReport::passengers($file);
        if ($rows->isEmpty()) {
            throw BusinessRuleException::make('import_empty', 'The file has no passenger rows.');
        }

        $errors = [];
        $seen = [];
        foreach ($rows as $line => $row) {
            $key = $this->otKey($row['person_code'], $row['first_name'], $row['last_name']);
            if (isset($seen[$key])) {
                $errors[] = "Row {$line}: the same person as row {$seen[$key]}.";
            }
            $seen[$key] ??= $line;

            if (! $row['first_name'] || ! $row['last_name']) {
                $errors[] = "Row {$line}: \"{$row['name']}\" needs a first and a last name.";
            } elseif (! preg_match('/^[A-Za-z][A-Za-z \'-]*$/', $row['first_name'].' '.$row['last_name'])) {
                $errors[] = "Row {$line}: \"{$row['name']}\" must be written in Latin letters.";
            }
        }
        if ($errors !== []) {
            throw BusinessRuleException::make('import_invalid', 'The file has errors; nothing was imported.', ['errors' => $errors]);
        }

        return DB::transaction(function () use ($rows, $allocation, $actor, $sync, $return) {
            $existing = Passenger::query()->whereNull('infant_of_passenger_id')
                ->whereHas('booking', fn ($q) => $q->where('agency_allocation_id', $allocation->id))
                ->get()
                ->keyBy(fn (Passenger $p) => $this->otKey($p->person_code, $p->first_name, $p->last_name));
            $inFile = $rows->mapWithKeys(fn ($row) => [$this->otKey($row['person_code'], $row['first_name'], $row['last_name']) => true]);

            // first let go of the workers no longer listed, so their seats go to the new ones
            $cancelled = 0;
            if ($sync) {
                foreach ($existing->reject(fn ($p, $key) => $inFile->has($key)) as $passenger) {
                    try {
                        $this->bookings->cancelPassenger($passenger, ActivityChannel::Agency, 'Not in the OT transport report any more');
                    } catch (BusinessRuleException $e) {
                        throw BusinessRuleException::make($e->errorCode, "{$passenger->last_name}/{$passenger->first_name}: ".$e->getMessage(), $e->context);
                    }
                    $cancelled++;
                }
            }

            $this->assertRoomForOt($allocation, $return, $rows->count(),
                $rows->reject(fn ($row) => $existing->has($this->otKey($row['person_code'], $row['first_name'], $row['last_name'])))->count());

            $pnrs = [];
            $created = $kept = $waitlisted = 0;
            foreach ($rows as $line => $row) {
                if ($existing->has($this->otKey($row['person_code'], $row['first_name'], $row['last_name']))) {
                    $kept++;

                    continue;
                }
                try {
                    $booking = $this->bookings->create([
                        'allocation_id' => $allocation->id,
                        'return_allocation_id' => $return?->id,
                        'passengers' => [[
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'gender' => $row['gender'],
                            'person_code' => $row['person_code'],
                            'phone' => $row['phone'],
                            'cabin_class' => 'Y',
                        ]],
                    ], $actor, BookingSource::Import);
                } catch (BusinessRuleException $e) {
                    throw BusinessRuleException::make($e->errorCode, "Row {$line}: ".$e->getMessage(), $e->context);
                }
                $pnrs[] = $booking->relationLoaded('linkedBooking') && $booking->linkedBooking
                    ? "{$booking->pnr} / {$booking->linkedBooking->pnr}" : $booking->pnr;
                $created++;
                $waitlisted += (int) ($booking->status === BookingStatus::Waitlisted);
            }

            return ['created' => $created, 'kept' => $kept, 'cancelled' => $cancelled, 'waitlisted' => $waitlisted, 'bookings' => $pnrs];
        });
    }

    /**
     * Says up front how many seats are missing, instead of failing on the first row past the room. A quota that
     * waitlists takes everyone (the rest are waitlisted in file order).
     */
    private function assertRoomForOt(AgencyAllocation $allocation, ?AgencyAllocation $return, int $inFile, int $new): void
    {
        foreach (array_filter([$allocation, $return]) as $quota) {
            if ($quota->is_waitlist) {
                continue;
            }
            $free = $this->allocations->freeToTake($quota, CabinClass::Economy);
            if ($new <= $free) {
                continue;
            }

            $flight = $quota->outboundFlight?->flight_number;
            $why = $quota->isPool()
                ? "the rest of flight {$flight}'s economy seats are sold or in other agencies' fixed quotas"
                : "the quota has {$quota->seats} seat(s)";
            throw BusinessRuleException::make('import_no_room',
                "Only {$free} seat(s) are free for this quota on {$flight} ({$why}), but the file adds {$new} new passenger(s)"
                .($new < $inFile ? ' ('.($inFile - $new).' already on the quota)' : '')
                .'. Nothing was imported: ask the airline for more seats or for a waitlist on the quota.',
                ['free' => $free, 'new' => $new, 'in_file' => $inFile, 'flight' => $flight]);
        }
    }

    /** One worker: the SAP number, or the name when the report has none (e.g. a local citizen) */
    private function otKey(?string $personCode, ?string $firstName, ?string $lastName): string
    {
        $code = mb_strtoupper(trim((string) $personCode));

        return $code !== '' ? "sap:{$code}" : 'name:'.mb_strtoupper(trim($firstName.' '.$lastName));
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
