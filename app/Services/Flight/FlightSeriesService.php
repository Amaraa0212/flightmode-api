<?php

namespace App\Services\Flight;

use App\Enums\ActivityChannel;
use App\Enums\FlightStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSeries;
use App\Services\ActivityLogger;
use App\Services\Inventory\InventoryService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A flight series (legacy "scheduled flight") generates one flight per operating day.
 * Saving a series synchronises its flights instead of deleting and re-creating them,
 * so flights that already have passengers are never lost.
 */
class FlightSeriesService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{series: FlightSeries, created: int, updated: int, removed: int, kept: array<int, string>}
     */
    public function save(array $data, ?FlightSeries $series = null): array
    {
        return DB::transaction(function () use ($data, $series) {
            $series ??= new FlightSeries(['created_by' => auth()->id()]);
            $series->fill($data)->save();
            $series->load(['origin', 'destination']);

            $result = $this->sync($series);
            $this->logger->log(ActivityChannel::Admin, 'flight_series.saved',
                "{$series->flight_number}: +{$result['created']} ~{$result['updated']} -{$result['removed']}", $series);

            return ['series' => $series] + $result;
        });
    }

    /** Deletes the series and every future flight of it that has no passengers. */
    public function delete(FlightSeries $series): array
    {
        return DB::transaction(function () use ($series) {
            $kept = [];
            $removed = 0;
            foreach ($series->flights()->withCount('segments')->get() as $flight) {
                if ($flight->segments_count > 0) {
                    $kept[] = $flight->scheduled_departure_at->toDateString();
                    $flight->update(['flight_series_id' => null]);
                } else {
                    $flight->delete();
                    $removed++;
                }
            }
            $series->delete();

            return ['removed' => $removed, 'kept' => $kept];
        });
    }

    /** @return array{created: int, updated: int, removed: int, kept: array<int, string>} */
    public function sync(FlightSeries $series): array
    {
        if (empty($series->weekdays())) {
            throw BusinessRuleException::make('no_weekdays', 'Choose at least one day of the week.');
        }

        $wanted = [];
        foreach (CarbonPeriod::create($series->period_start, $series->period_end) as $day) {
            if (in_array($day->dayOfWeekIso, $series->weekdays(), true)) {
                $wanted[$day->toDateString()] = $this->times($series, $day);
            }
        }

        $existing = $series->flights()->withCount('segments')->get()
            ->keyBy(fn (Flight $f) => $f->scheduled_departure_at->copy()->timezone($series->origin->timezone)->toDateString());

        $created = $updated = $removed = 0;
        $kept = [];

        foreach ($wanted as $date => [$departure, $arrival]) {
            $attributes = [
                'airline_id' => $series->airline_id,
                'aircraft_id' => $series->aircraft_id,
                'flight_number' => $series->flight_number,
                'service_type' => $series->service_type ?? 'scheduled',
                'origin_airport_id' => $series->origin_airport_id,
                'destination_airport_id' => $series->destination_airport_id,
                'scheduled_departure_at' => $departure,
                'scheduled_arrival_at' => $arrival,
                'gate' => $series->gate,
                'excess_baggage_max_kg' => $series->excess_baggage_max_kg,
                'excess_baggage_price_per_kg' => $series->excess_baggage_price_per_kg,
            ];

            $flight = $existing->get($date);
            if (! $flight) {
                $flight = $series->flights()->create($attributes + ['status' => FlightStatus::Scheduled, 'created_by' => auth()->id()]);
                $this->applyInventoryTemplate($series, $flight);
                $created++;
            } elseif ($flight->status === FlightStatus::Scheduled && ! $flight->is_series_override) {
                $flight->fill($attributes);
                if ($flight->isDirty()) {
                    $flight->save();
                    $updated++;
                }
            }
        }

        foreach ($existing as $date => $flight) {
            if (isset($wanted[$date])) {
                continue;
            }
            if ($flight->segments_count > 0 || $flight->status !== FlightStatus::Scheduled || $flight->is_series_override) {
                $kept[] = $date;

                continue;
            }
            $flight->delete();
            $removed++;
        }

        return compact('created', 'updated', 'removed', 'kept');
    }

    /** A template that does not fit the flight (e.g. another aircraft) leaves it without inventory. */
    private function applyInventoryTemplate(FlightSeries $series, Flight $flight): void
    {
        if (! $series->inventory_template_id || ! $series->inventoryTemplate) {
            return;
        }

        try {
            $this->inventory->initialize($flight, $series->inventoryTemplate);
        } catch (BusinessRuleException) {
            // the flight keeps selling without RBD limits; set it on the seat allocation screen
        }
    }

    /**
     * Local departure / arrival times of one operating day, converted to UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function times(FlightSeries $series, Carbon $day): array
    {
        /** @var Airport $origin */
        $origin = $series->origin;
        /** @var Airport $destination */
        $destination = $series->destination;

        $departure = Carbon::parse($day->toDateString().' '.$series->departure_time, $origin->timezone)->utc();
        $arrival = Carbon::parse(
            $day->copy()->addDays((int) $series->arrival_day_offset)->toDateString().' '.$series->arrival_time,
            $destination->timezone,
        )->utc();

        if ($arrival->lte($departure)) {
            throw BusinessRuleException::make('arrival_before_departure', 'Arrival must be after departure (check the next-day flag).');
        }

        return [$departure, $arrival];
    }
}
