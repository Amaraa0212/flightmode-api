# Legacy (flightmodev2) → API: where each rule lives now

Table / column mapping is in `docs/database.md`. This file maps **business logic**: the legacy app repeated most
rules in several controllers; each rule below now has exactly one home.

## Rules and their single home

| Rule | Legacy behaviour | New home |
|---|---|---|
| Passenger age bands | INF < 2, CHD < 12, computed in several places | `Support\PassengerAge::typeOn()` |
| Passport validity | ≥ 6 months after departure, international only | `Booking\PassengerRules::validate()` |
| Infant rules | one infant per adult, aircraft infant limit | `PassengerRules`, `BookingService::assertInfantCapacity()` |
| Duplicate passenger | same document on the same flight | `BookingService::assertNotDuplicate()` |
| Names deadline | deadline day = departure day → closes 2 h before departure | `AgencyAllocation::namesDeadlineAt()` |
| Allocation / cabin / fare quota | `agent_schedule`, `fare == 1` flag = uses fare quotas | `Allocation\AllocationService::assertCanTake()` / `usage()` |
| Pricing | fare base + tax, `CHD_P` / `INF_P` discount % | `Fare::priceFor()`, `AgencyAllocationFare::priceFor()` |
| Round trip | both coupons, priced once | `BookingService::createSegments()` |
| PNR | random, unique | `Booking\PnrGenerator` |
| Ticket number | airline numeric code + zero-padded passenger id (13) | `Booking\TicketNumberGenerator` |
| Seat availability | occupied / blocked / held / restricted | `Seat\SeatMapService` |
| Auto seat | `seat_zone` priority order | `CheckinService::autoSeat()` + `seat_assignment_profiles` |
| Online seat zones | `airplane_rule` pax thresholds | `SeatMapService::closedZonesForOnline()` + `aircraft_load_zones` |
| Check-in / uncheck / board / offload | `tkt_status` 0→1→2, infant follows adult | `Checkin\CheckinService` |
| Baggage allowance / excess price | DCS `Baggage.vue` calculated and sent prices | `Baggage\BaggagePricing::quote()` (client values ignored, `excess_limit_exceeded`) |
| Ban list | passenger report "Ban Type / Ban Description" | `Checkin\PassengerScreening` (`passenger_banned` blocks check-in, `watch` warns) |
| End check-in (with / without OCI) | `endCKI(closeWithOCI)` → status 2 / 0 | `FlightOperationsService::closeCheckin($closeOnline)` → checkin_closed / scheduled |
| Live counters | page refresh | `FlightUpdated` broadcast from `ActivityLogger` on `private-dcs.flight.{id}` (Reverb) |
| Un-check needs baggage offloaded | DCS `User.vue`: "Offload the baggage first" (frontend only) | `CheckinService::cancelCheckin()` (`baggage_not_offloaded`) |
| Bag tag number | `1` + numeric code + pax seq (3) + bag seq (3) | `BaggageService::createTags()` |
| Baggage piece limit | per rule, prepaid (XBAG) pieces | `BaggageRule::pieceLimit()` |
| BCBP barcode | IATA M1 + `"<seat> <segment id>"` | `Checkin\BoardingPassService::bcbp()` / `parseScan()` |
| Flight life cycle | `schedules.status` 0–4, `time1/2/3` | `Flight\FlightOperationsService` |
| Series → flights | generate dated flights | `Flight\FlightSeriesService` (keeps flights that have passengers) |
| PNL | text telex with routing | `Documents\PnlGenerator` |
| Manifest / balance / excess | several pages | `Documents\ManifestService` (+ `ManifestExport` xlsx) |
| E-ticket | PDF + e-mail | `Documents\ETicketService` |
| Chinggis sync | hard-coded agent 119 | `Integrations\Chinggis\ChinggisSync`, `CHINGGIS_AGENCY_IDS` |
| Activity logs | 3 log tables | `ActivityLogger` → `activity_logs` |

## Intentional differences from legacy

1. **Load zones:** a zone whose passenger threshold has not been reached yet is treated as *closed* for online
   check-in (legacy behaviour depended on row order).
2. **Crew bag tags** use `999` as the passenger sequence, so they can never collide with passenger tags.
3. **Online check-in** needs an e-mailed 4-digit verification code after the SAP number (`person_code`) lookup.
   The code is bound to one lookup; the session token is bound to the bookings found by that lookup.
4. **Negdi payment** in online check-in is **not** ported (per decision). Paid seats are shown with their price
   but online check-in does not charge.
5. The unauthenticated delete endpoint of the legacy online check-in was **not** ported.
6. **Partner tokens** (agency external API) only work on `/api/v1/partner/*`; login tokens do not work there.
7. The DCS counter may move a passenger to a seat in another cabin (upgrade); the segment's cabin follows the seat.
   Online check-in cannot.
8. QR codes and barcodes are rendered by the frontend; the API returns the data string.

## Tests (`php artisan test`)

| Area | File | Covers |
|---|---|---|
| Auth | `Feature/Auth/AuthTest` | login returns token + permissions, wrong password, disabled user, permission check, 401 JSON |
| Admin | `Feature/Admin/FlightSetupTest` | series generation in UTC, seat map generation, flight life cycle, access scope |
| Agency | `Feature/Agency/BookingTest` | fare quota pricing + infant, duplicate document, cabin quota, waitlist, passenger rules, names deadline, round trip, agency isolation / cancel |
| Partner | `Feature/Agency/PartnerApiTest` | token issue, booking via partner API, token separation, disabled agency, charter request flow |
| DCS counter | `Feature/Dcs/CounterFeaturesTest` | server-side excess pricing + cap, crew bag name, ban / watch screening, flight broadcast, channel auth by station, station announcements |
| DCS app data | `Feature/Dcs/DepartureBoardTest`, `Feature/Dcs/DcsDemoSeederTest` | board passenger counts, station on `/auth/me`, one segment, bag owner, un-check with bags, demo seeder |
| DCS / Gate | `Feature/Dcs/CheckinFlowTest` | open counter, auto seat, seat change, upgrade, uncheck, baggage tags + limits, crew bags, boarding pass BCBP, gate boarding by scan, offload, go-show, station scope |
| Online check-in | `Feature/Checkin/OnlineCheckinTest` | lookup → code → verify → seat map (restricted zone) → seat hold → check-in |
| Documents | `Feature/Documents/FlightDocumentsTest` | PNL, manifest JSON, xlsx downloads, e-mail, e-ticket PDF, reports, dashboard |
