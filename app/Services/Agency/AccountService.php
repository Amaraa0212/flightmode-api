<?php

namespace App\Services\Agency;

use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAccount;
use App\Models\AgencyAccountTransaction;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid agency accounts, one per agency and airline. The airline tops them up; a ticket written on an agency
 * booking takes its price (fare + tax) from the balance and a cancellation gives it back in full.
 * An agency without an account with the airline sells as before, without a balance check.
 */
class AccountService
{
    /** @param 'topup'|'adjustment' $type */
    public function topUp(int $agencyId, int $airlineId, float $amount, string $type, ?string $note, User $actor): AgencyAccountTransaction
    {
        return DB::transaction(function () use ($agencyId, $airlineId, $amount, $type, $note, $actor) {
            $airline = Airline::query()->findOrFail($airlineId);
            AgencyAccount::query()->firstOrCreate(
                ['agency_id' => $agencyId, 'airline_id' => $airlineId],
                ['currency' => $airline->currency ?? 'MNT'],
            );
            $account = AgencyAccount::query()->where('agency_id', $agencyId)->where('airline_id', $airlineId)->lockForUpdate()->firstOrFail();

            if ($type === AgencyAccountTransaction::TOPUP && $amount <= 0) {
                throw BusinessRuleException::make('topup_not_positive', 'A top-up must be more than zero.');
            }
            if ((float) $account->balance + $amount < 0) {
                throw BusinessRuleException::make('balance_below_zero', 'The balance cannot go below zero.');
            }

            return $this->post($account, $type, $amount, null, null, $note, $actor->id);
        });
    }

    /**
     * Bring the charges of every passenger of the booking in line with what they hold: a ticketed passenger pays the
     * price of their segments that hold a place, a cancelled / waitlisted one pays nothing. Call inside the transaction
     * that changed the booking — a balance that is too low rolls it back.
     */
    public function syncBooking(Booking $booking): void
    {
        if (! $booking->agency_id) {
            return;
        }

        $account = AgencyAccount::query()->where('agency_id', $booking->agency_id)->where('airline_id', $booking->airline_id)
            ->lockForUpdate()->first();
        if (! $account) {
            return;
        }

        $passengers = Passenger::withTrashed()->with('segments')->where('booking_id', $booking->id)->get();
        foreach ($passengers as $passenger) {
            $due = $passenger->trashed() ? 0.0 : (float) $passenger->segments
                ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
                ->sum(fn ($s) => (float) $s->fare_amount + (float) $s->tax_amount);
            // charges are negative, refunds positive
            $paid = -(float) $account->transactions()->where('passenger_id', $passenger->id)->sum('amount');
            $delta = round($due - $paid, 2);

            if ($delta > 0) {
                if ((float) $account->balance < $delta) {
                    throw BusinessRuleException::make('insufficient_balance',
                        'Not enough balance: '.number_format($delta, 2).' '.$account->currency.' needed, '
                        .number_format((float) $account->balance, 2).' left. Ask the airline to top up the account.');
                }
                $this->post($account, AgencyAccountTransaction::TICKET, -$delta, $passenger->id, $booking->id,
                    "PNR {$booking->pnr} {$passenger->last_name}/{$passenger->first_name}", auth()->id());
            } elseif ($delta < 0) {
                $this->post($account, AgencyAccountTransaction::REFUND, -$delta, $passenger->id, $booking->id,
                    "PNR {$booking->pnr} {$passenger->last_name}/{$passenger->first_name}", auth()->id());
            }
        }
    }

    private function post(AgencyAccount $account, string $type, float $amount, ?int $passengerId, ?int $bookingId, ?string $note, ?int $userId): AgencyAccountTransaction
    {
        $account->balance = round((float) $account->balance + $amount, 2);
        $account->save();

        return $account->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $account->balance,
            'passenger_id' => $passengerId,
            'booking_id' => $bookingId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }
}
