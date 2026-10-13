# API routes

Generated from `php artisan route:list` — 225 routes. All under `/api/v1`, JSON, snake_case.

Permission = `can:` middleware (system admin passes everything).

## Auth

Broadcasting (Laravel Reverb): `POST /api/broadcasting/auth` — Sanctum Bearer, authorises `private-dcs.flight.{id}` (see `routes/channels.php`).

| Method | URI | Permission | Action |
|---|---|---|---|
| POST | `/api/v1/auth/login` |  | AuthController@login |
| POST | `/api/v1/auth/logout` |  | AuthController@logout |
| GET | `/api/v1/auth/me` |  | AuthController@me |
| PUT | `/api/v1/auth/password` |  | AuthController@changePassword |

## Online check-in (public, X-Checkin-Token)

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/checkin/flights/{flight}/boarding-passes` |  | OnlineCheckinController@boardingPasses |
| POST | `/api/v1/checkin/flights/{flight}/check-in` |  | OnlineCheckinController@checkIn |
| GET | `/api/v1/checkin/flights/{flight}/passengers` |  | OnlineCheckinController@passengers |
| GET | `/api/v1/checkin/flights/{flight}/policies` |  | OnlineCheckinController@policies |
| POST | `/api/v1/checkin/flights/{flight}/seat-hold` |  | OnlineCheckinController@holdSeat |
| GET | `/api/v1/checkin/flights/{flight}/seat-map` |  | OnlineCheckinController@seatMap |
| POST | `/api/v1/checkin/lookup` |  | OnlineCheckinController@lookup |
| POST | `/api/v1/checkin/verification-code` |  | OnlineCheckinController@sendCode |
| POST | `/api/v1/checkin/verify` |  | OnlineCheckinController@verify |

## Partner API (agency machine-to-machine, partner token)

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/partner/allocations` |  | AllocationController@index |
| GET | `/api/v1/partner/allocations/{allocation}` |  | AllocationController@show |
| GET | `/api/v1/partner/allocations/{allocation}/passengers` |  | AllocationController@passengers |
| GET | `/api/v1/partner/allocations/{allocation}/ssr-codes` |  | AllocationController@ssrCodes |
| POST | `/api/v1/partner/bookings` |  | BookingController@store |
| DELETE | `/api/v1/partner/passengers/{passenger}` |  | BookingController@cancelPassenger |
| GET | `/api/v1/partner/passengers/{passenger}` |  | BookingController@showPassenger |
| PATCH | `/api/v1/partner/passengers/{passenger}` |  | BookingController@updatePassenger |
| GET | `/api/v1/partner/passengers/{passenger}/eticket` |  | BookingController@eticket |

## Shared (any logged-in user)

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/announcements` |  | AnnouncementFeedController@index |
| POST | `/api/v1/announcements/{announcement}/dismiss` |  | AnnouncementFeedController@dismiss |
| GET | `/api/v1/ping` |  | Closure |
| GET | `/api/v1/reference/airports` |  | ReferenceController@airports |
| GET | `/api/v1/reference/countries` |  | ReferenceController@countries |
| GET | `/api/v1/reference/enums` |  | ReferenceController@enums |
| GET | `/api/v1/reference/ssr-codes` |  | ReferenceController@ssrCodes |

## Admin / back-office

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/admin/activity-logs` | activity_logs.view | ActivityLogController@index |
| GET | `/api/v1/admin/agencies` | agencies.manage | AgencyController@index |
| POST | `/api/v1/admin/agencies` | agencies.manage | AgencyController@store |
| DELETE | `/api/v1/admin/agencies/{agency}` | agencies.manage | AgencyController@destroy |
| GET | `/api/v1/admin/agencies/{agency}` | agencies.manage | AgencyController@show |
| PUT|PATCH | `/api/v1/admin/agencies/{agency}` | agencies.manage | AgencyController@update |
| GET | `/api/v1/admin/aircraft` | aircraft.manage | AircraftController@index |
| POST | `/api/v1/admin/aircraft` | aircraft.manage | AircraftController@store |
| DELETE | `/api/v1/admin/aircraft/{aircraft}` | aircraft.manage | AircraftController@destroy |
| GET | `/api/v1/admin/aircraft/{aircraft}` | aircraft.manage | AircraftController@show |
| PUT|PATCH | `/api/v1/admin/aircraft/{aircraft}` | aircraft.manage | AircraftController@update |
| GET | `/api/v1/admin/aircraft/{aircraft}/load-zones` | aircraft.manage | AircraftLoadZoneController@index |
| PUT | `/api/v1/admin/aircraft/{aircraft}/load-zones` | aircraft.manage | AircraftLoadZoneController@replace |
| GET | `/api/v1/admin/aircraft/{aircraft}/seat-assignment-profiles` | aircraft.manage | SeatAssignmentProfileController@index |
| POST | `/api/v1/admin/aircraft/{aircraft}/seat-assignment-profiles` | aircraft.manage | SeatAssignmentProfileController@store |
| DELETE | `/api/v1/admin/aircraft/{aircraft}/seat-assignment-profiles/{profile}` | aircraft.manage | SeatAssignmentProfileController@destroy |
| PUT|PATCH | `/api/v1/admin/aircraft/{aircraft}/seat-assignment-profiles/{profile}` | aircraft.manage | SeatAssignmentProfileController@update |
| DELETE | `/api/v1/admin/aircraft/{aircraft}/seats` | aircraft.manage | AircraftSeatController@destroyRows |
| GET | `/api/v1/admin/aircraft/{aircraft}/seats` | aircraft.manage | AircraftSeatController@index |
| POST | `/api/v1/admin/aircraft/{aircraft}/seats/generate` | aircraft.manage | AircraftSeatController@generate |
| PATCH | `/api/v1/admin/aircraft/{aircraft}/seats/{seat}` | aircraft.manage | AircraftSeatController@update |
| GET | `/api/v1/admin/airline-ssrs` | products.manage | AirlineSsrController@index |
| POST | `/api/v1/admin/airline-ssrs` | products.manage | AirlineSsrController@store |
| DELETE | `/api/v1/admin/airline-ssrs/{airline_ssr}` | products.manage | AirlineSsrController@destroy |
| PUT|PATCH | `/api/v1/admin/airline-ssrs/{airline_ssr}` | products.manage | AirlineSsrController@update |
| GET | `/api/v1/admin/airlines` | airlines.manage | AirlineController@index |
| POST | `/api/v1/admin/airlines` | airlines.manage | AirlineController@store |
| DELETE | `/api/v1/admin/airlines/{airline}` | airlines.manage | AirlineController@destroy |
| GET | `/api/v1/admin/airlines/{airline}` | airlines.manage | AirlineController@show |
| PUT|PATCH | `/api/v1/admin/airlines/{airline}` | airlines.manage | AirlineController@update |
| GET | `/api/v1/admin/airlines/{airline}/agencies` | airlines.manage | AirlineController@agencies |
| DELETE | `/api/v1/admin/airlines/{airline}/agencies/{agency}` | airlines.manage | AirlineController@removeAgencyContract |
| PUT | `/api/v1/admin/airlines/{airline}/agencies/{agency}` | airlines.manage | AirlineController@setAgencyContract |
| GET | `/api/v1/admin/airports` | reference.manage | AirportController@index |
| POST | `/api/v1/admin/airports` | reference.manage | AirportController@store |
| DELETE | `/api/v1/admin/airports/{airport}` | reference.manage | AirportController@destroy |
| GET | `/api/v1/admin/airports/{airport}` | reference.manage | AirportController@show |
| PUT|PATCH | `/api/v1/admin/airports/{airport}` | reference.manage | AirportController@update |
| GET | `/api/v1/admin/allocations` | allocations.manage | AllocationController@index |
| POST | `/api/v1/admin/allocations` | allocations.manage | AllocationController@store |
| DELETE | `/api/v1/admin/allocations/{allocation}` | allocations.manage | AllocationController@destroy |
| GET | `/api/v1/admin/allocations/{allocation}` | allocations.manage | AllocationController@show |
| PUT|PATCH | `/api/v1/admin/allocations/{allocation}` | allocations.manage | AllocationController@update |
| GET | `/api/v1/admin/allocations/{allocation}/passengers` | allocations.manage | AllocationController@passengers |
| GET | `/api/v1/admin/announcements` | announcements.manage | AnnouncementController@index |
| POST | `/api/v1/admin/announcements` | announcements.manage | AnnouncementController@store |
| DELETE | `/api/v1/admin/announcements/{announcement}` | announcements.manage | AnnouncementController@destroy |
| PUT|PATCH | `/api/v1/admin/announcements/{announcement}` | announcements.manage | AnnouncementController@update |
| GET | `/api/v1/admin/baggage-policies` | products.manage | BaggagePolicyController@index |
| POST | `/api/v1/admin/baggage-policies` | products.manage | BaggagePolicyController@store |
| DELETE | `/api/v1/admin/baggage-policies/{id}` | products.manage | BaggagePolicyController@destroy |
| GET | `/api/v1/admin/baggage-policies/{id}` | products.manage | BaggagePolicyController@show |
| PUT|PATCH | `/api/v1/admin/baggage-policies/{id}` | products.manage | BaggagePolicyController@update |
| GET | `/api/v1/admin/baggage-rules` | products.manage | BaggageRuleController@index |
| POST | `/api/v1/admin/baggage-rules` | products.manage | BaggageRuleController@store |
| DELETE | `/api/v1/admin/baggage-rules/{id}` | products.manage | BaggageRuleController@destroy |
| GET | `/api/v1/admin/baggage-rules/{id}` | products.manage | BaggageRuleController@show |
| PUT|PATCH | `/api/v1/admin/baggage-rules/{id}` | products.manage | BaggageRuleController@update |
| GET | `/api/v1/admin/bookings` | bookings.view | BookingController@index |
| GET | `/api/v1/admin/passenger-bans` | bookings.manage | PassengerBanController@index |
| POST | `/api/v1/admin/passenger-bans` | bookings.manage | PassengerBanController@store |
| GET | `/api/v1/admin/passenger-bans/{passenger_ban}` | bookings.manage | PassengerBanController@show |
| PUT | `/api/v1/admin/passenger-bans/{passenger_ban}` | bookings.manage | PassengerBanController@update |
| DELETE | `/api/v1/admin/passenger-bans/{passenger_ban}` | bookings.manage | PassengerBanController@destroy |
| POST | `/api/v1/admin/bookings` | bookings.manage | BookingController@store |
| POST | `/api/v1/admin/bookings/merge` | bookings.manage | BookingController@merge |
| GET | `/api/v1/admin/bookings/{booking}` | bookings.view | BookingController@show |
| GET | `/api/v1/admin/charter-requests` | charters.view | CharterRequestController@index |
| POST | `/api/v1/admin/charter-requests` | charters.manage | CharterRequestController@store |
| GET | `/api/v1/admin/charter-requests/{charterRequest}` | charters.view | CharterRequestController@show |
| PATCH | `/api/v1/admin/charter-requests/{charterRequest}/status` | charters.manage | CharterRequestController@updateStatus |
| POST | `/api/v1/admin/countries` | reference.manage | CountryController@store |
| DELETE | `/api/v1/admin/countries/{country}` | reference.manage | CountryController@destroy |
| PUT|PATCH | `/api/v1/admin/countries/{country}` | reference.manage | CountryController@update |
| GET | `/api/v1/admin/dashboard` | dashboard.view | DashboardController |
| GET | `/api/v1/admin/fares` | fares.manage | FareController@index |
| POST | `/api/v1/admin/fares` | fares.manage | FareController@store |
| DELETE | `/api/v1/admin/fares/{id}` | fares.manage | FareController@destroy |
| GET | `/api/v1/admin/fares/{id}` | fares.manage | FareController@show |
| PUT|PATCH | `/api/v1/admin/fares/{id}` | fares.manage | FareController@update |
| GET | `/api/v1/admin/rbds` | fares.manage | RbdController@index |
| POST | `/api/v1/admin/rbds` | fares.manage | RbdController@store |
| GET | `/api/v1/admin/rbds/{id}` | fares.manage | RbdController@show |
| PUT|PATCH | `/api/v1/admin/rbds/{id}` | fares.manage | RbdController@update |
| DELETE | `/api/v1/admin/rbds/{id}` | fares.manage | RbdController@destroy |
| GET | `/api/v1/admin/fare-classes` | fares.manage | FareClassController@index |
| POST | `/api/v1/admin/fare-classes` | fares.manage | FareClassController@store |
| GET | `/api/v1/admin/fare-classes/{id}` | fares.manage | FareClassController@show |
| PUT|PATCH | `/api/v1/admin/fare-classes/{id}` | fares.manage | FareClassController@update |
| DELETE | `/api/v1/admin/fare-classes/{id}` | fares.manage | FareClassController@destroy |
| GET | `/api/v1/admin/tax-codes` | fares.manage | TaxCodeController@index |
| POST | `/api/v1/admin/tax-codes` | fares.manage | TaxCodeController@store |
| GET | `/api/v1/admin/tax-codes/{id}` | fares.manage | TaxCodeController@show |
| PUT|PATCH | `/api/v1/admin/tax-codes/{id}` | fares.manage | TaxCodeController@update |
| DELETE | `/api/v1/admin/tax-codes/{id}` | fares.manage | TaxCodeController@destroy |
| GET | `/api/v1/admin/fare-taxes` | fares.manage | FareTaxController@index |
| POST | `/api/v1/admin/fare-taxes` | fares.manage | FareTaxController@store |
| GET | `/api/v1/admin/fare-taxes/{id}` | fares.manage | FareTaxController@show |
| PUT|PATCH | `/api/v1/admin/fare-taxes/{id}` | fares.manage | FareTaxController@update |
| DELETE | `/api/v1/admin/fare-taxes/{id}` | fares.manage | FareTaxController@destroy |
| GET | `/api/v1/admin/rbd-modifications` | fares.manage | RbdModificationController@index |
| POST | `/api/v1/admin/rbd-modifications` | fares.manage | RbdModificationController@store |
| GET | `/api/v1/admin/rbd-modifications/{id}` | fares.manage | RbdModificationController@show |
| PUT|PATCH | `/api/v1/admin/rbd-modifications/{id}` | fares.manage | RbdModificationController@update |
| DELETE | `/api/v1/admin/rbd-modifications/{id}` | fares.manage | RbdModificationController@destroy |
| GET | `/api/v1/admin/fare-classes/{fareClass}/discounts` | fares.manage | FareDiscountController@index |
| POST | `/api/v1/admin/fare-classes/{fareClass}/discounts` | fares.manage | FareDiscountController@store |
| PUT|PATCH | `/api/v1/admin/fare-classes/{fareClass}/discounts/{discount}` | fares.manage | FareDiscountController@update |
| DELETE | `/api/v1/admin/fare-classes/{fareClass}/discounts/{discount}` | fares.manage | FareDiscountController@destroy |
| GET | `/api/v1/admin/flight-board` | flights.operate | FlightOperationController@board |
| GET | `/api/v1/admin/flight-series` | flights.manage | FlightSeriesController@index |
| POST | `/api/v1/admin/flight-series` | flights.manage | FlightSeriesController@store |
| DELETE | `/api/v1/admin/flight-series/{flightSeries}` | flights.manage | FlightSeriesController@destroy |
| GET | `/api/v1/admin/flight-series/{flightSeries}` | flights.manage | FlightSeriesController@show |
| PUT|PATCH | `/api/v1/admin/flight-series/{flightSeries}` | flights.manage | FlightSeriesController@update |
| GET | `/api/v1/admin/flights` | flights.view | FlightController@index |
| POST | `/api/v1/admin/flights` | flights.manage | FlightController@store |
| DELETE | `/api/v1/admin/flights/{flight}` | flights.manage | FlightController@destroy |
| GET | `/api/v1/admin/flights/{flight}` | flights.view | FlightController@show |
| PUT | `/api/v1/admin/flights/{flight}` | flights.manage | FlightController@update |
| GET | `/api/v1/admin/flights/{flight}/allocation-capacity` | flights.view | FlightController@allocationCapacity |
| GET | `/api/v1/admin/flights/{flight}/documents/apis` | reports.view | FlightDocumentController@apis |
| POST | `/api/v1/admin/flights/{flight}/documents/email` | reports.view | FlightDocumentController@email |
| GET | `/api/v1/admin/flights/{flight}/documents/manifest` | reports.view | FlightDocumentController@manifest |
| GET | `/api/v1/admin/flights/{flight}/documents/no-show` | reports.view | FlightDocumentController@noShow |
| GET | `/api/v1/admin/flights/{flight}/documents/passengers` | reports.view | FlightDocumentController@passengers |
| GET | `/api/v1/admin/flights/{flight}/documents/pnl` | reports.view | FlightDocumentController@pnl |
| PUT | `/api/v1/admin/flights/{flight}/estimated-departure` | flights.operate | FlightOperationController@setEstimatedDeparture |
| GET | `/api/v1/admin/flights/{flight}/fares` | flights.view | FareController@forFlight |
| PUT | `/api/v1/admin/flights/{flight}/gate` | flights.operate | FlightOperationController@setGate |
| PUT | `/api/v1/admin/flights/{flight}/gate-agents` | flights.operate | FlightOperationController@setGateAgents |
| GET | `/api/v1/admin/flights/{flight}/gate-agents/candidates` | flights.operate | FlightOperationController@gateAgentCandidates |
| PUT | `/api/v1/admin/flights/{flight}/online-checkin` | flights.operate | FlightOperationController@setOnlineCheckin |
| GET | `/api/v1/admin/flights/{flight}/passengers` | bookings.view | FlightController@passengers |
| DELETE | `/api/v1/admin/flights/{flight}/seat-blocks` | seat_blocks.manage | SeatBlockController@unblock |
| POST | `/api/v1/admin/flights/{flight}/seat-blocks` | seat_blocks.manage | SeatBlockController@block |
| GET | `/api/v1/admin/flights/{flight}/seat-map` | seat_blocks.manage | SeatBlockController@seatMap |
| GET | `/api/v1/admin/flights/{flight}/statistics` | flights.view | FlightController@statistics |
| POST | `/api/v1/admin/flights/{flight}/status` | flights.operate | FlightOperationController@changeStatus |
| DELETE | `/api/v1/admin/passengers/{passenger}` | bookings.manage | PassengerController@destroy |
| GET | `/api/v1/admin/passengers/{passenger}` | bookings.view | PassengerController@show |
| PUT | `/api/v1/admin/passengers/{passenger}` | bookings.manage | PassengerController@update |
| GET | `/api/v1/admin/passengers/{passenger}/activity` | bookings.view | PassengerController@activity |
| GET | `/api/v1/admin/passengers/{passenger}/eticket` | bookings.view | PassengerController@eticket |
| POST | `/api/v1/admin/passengers/{passenger}/eticket/email` | bookings.manage | PassengerController@emailEticket |
| POST | `/api/v1/admin/passengers/{passenger}/split` | bookings.manage | BookingController@split |
| GET | `/api/v1/admin/permissions` | roles.manage | RoleController@permissions |
| GET | `/api/v1/admin/reports/fare-sales` | reports.view | ReportController@fareSales |
| GET | `/api/v1/admin/reports/online-checkin` | reports.view | ReportController@onlineCheckin |
| GET | `/api/v1/admin/reports/passenger-search` | reports.view | ReportController@passengerSearch |
| GET | `/api/v1/admin/reports/routes` | reports.view | ReportController@routes |
| GET | `/api/v1/admin/reports/traffic` | reports.view | ReportController@traffic |
| GET | `/api/v1/admin/roles` | roles.manage | RoleController@index |
| POST | `/api/v1/admin/roles` | roles.manage | RoleController@store |
| DELETE | `/api/v1/admin/roles/{role}` | roles.manage | RoleController@destroy |
| GET | `/api/v1/admin/roles/{role}` | roles.manage | RoleController@show |
| PUT|PATCH | `/api/v1/admin/roles/{role}` | roles.manage | RoleController@update |
| GET | `/api/v1/admin/seat-prices` | products.manage | SeatPriceController@index |
| POST | `/api/v1/admin/seat-prices` | products.manage | SeatPriceController@store |
| DELETE | `/api/v1/admin/seat-prices/{id}` | products.manage | SeatPriceController@destroy |
| GET | `/api/v1/admin/seat-prices/{id}` | products.manage | SeatPriceController@show |
| PUT|PATCH | `/api/v1/admin/seat-prices/{id}` | products.manage | SeatPriceController@update |
| POST | `/api/v1/admin/segments/waitlist` | bookings.manage | BookingController@waitlist |
| POST | `/api/v1/admin/segments/{segment}/rebook` | bookings.manage | BookingController@rebook |
| GET | `/api/v1/admin/service-templates` | products.manage | ServiceTemplateController@index |
| POST | `/api/v1/admin/service-templates` | products.manage | ServiceTemplateController@store |
| DELETE | `/api/v1/admin/service-templates/{id}` | products.manage | ServiceTemplateController@destroy |
| GET | `/api/v1/admin/service-templates/{id}` | products.manage | ServiceTemplateController@show |
| PUT|PATCH | `/api/v1/admin/service-templates/{id}` | products.manage | ServiceTemplateController@update |
| POST | `/api/v1/admin/service-templates/{id}/apply` | flights.operate | ServiceTemplateController@apply |
| POST | `/api/v1/admin/ssr-codes` | reference.manage | SsrCodeController@store |
| DELETE | `/api/v1/admin/ssr-codes/{ssr_code}` | reference.manage | SsrCodeController@destroy |
| PUT|PATCH | `/api/v1/admin/ssr-codes/{ssr_code}` | reference.manage | SsrCodeController@update |
| GET | `/api/v1/admin/users` | users.manage | UserController@index |
| POST | `/api/v1/admin/users` | users.manage | UserController@store |
| DELETE | `/api/v1/admin/users/{user}` | users.manage | UserController@destroy |
| GET | `/api/v1/admin/users/{user}` | users.manage | UserController@show |
| PUT|PATCH | `/api/v1/admin/users/{user}` | users.manage | UserController@update |
| DELETE | `/api/v1/admin/users/{user}/api-token` | users.manage | UserController@revokeApiToken |
| POST | `/api/v1/admin/users/{user}/api-token` | users.manage | UserController@issueApiToken |

## Agency portal

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/agency/allocations` | agency.portal | AllocationController@index |
| GET | `/api/v1/agency/allocations/{allocation}` | agency.portal | AllocationController@show |
| POST | `/api/v1/agency/allocations/{allocation}/import` | agency.portal | BookingController@import |
| GET | `/api/v1/agency/allocations/{allocation}/passengers` | agency.portal | AllocationController@passengers |
| GET | `/api/v1/agency/allocations/{allocation}/return-options` | agency.portal | AllocationController@returnOptions |
| GET | `/api/v1/agency/allocations/{allocation}/ssr-codes` | agency.portal | AllocationController@ssrCodes |
| POST | `/api/v1/agency/bookings` | agency.portal | BookingController@store |
| GET | `/api/v1/agency/charter-requests` | charters.manage | CharterRequestController@index |
| POST | `/api/v1/agency/charter-requests` | charters.manage | CharterRequestController@store |
| GET | `/api/v1/agency/passenger-lookup` | agency.portal | BookingController@lookup |
| DELETE | `/api/v1/agency/passengers/{passenger}` | agency.portal | BookingController@cancelPassenger |
| GET | `/api/v1/agency/passengers/{passenger}` | agency.portal | BookingController@showPassenger |
| PUT | `/api/v1/agency/passengers/{passenger}` | agency.portal | BookingController@updatePassenger |
| GET | `/api/v1/agency/passengers/{passenger}/eticket` | agency.portal | BookingController@eticket |
| POST | `/api/v1/agency/passengers/{passenger}/eticket/email` | agency.portal | BookingController@emailEticket |

## DCS (check-in counter)

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/dcs/baggage-tags/{tag}` | dcs.access | BaggageController@tag |
| GET | `/api/v1/dcs/qz/certificate` | dcs.access | QzController@certificate — QZ Tray site certificate (null when not installed) |
| POST | `/api/v1/dcs/qz/sign` | dcs.access | QzController@sign — SHA512/RSA signature of a QZ Tray request (throttle 120/min) |
| DELETE | `/api/v1/dcs/baggage/{checkedBaggage}` | dcs.checkin | BaggageController@destroy |
| GET | `/api/v1/dcs/flights` | dcs.access | FlightController@index |
| GET | `/api/v1/dcs/flights/{flight}` | dcs.access | FlightController@show |
| GET | `/api/v1/dcs/flights/{flight}/baggage` | dcs.access | BaggageController@index |
| POST | `/api/v1/dcs/flights/{flight}/close` | dcs.checkin | FlightController@close |
| POST | `/api/v1/dcs/flights/{flight}/crew-baggage` | dcs.checkin | BaggageController@storeCrew |
| GET | `/api/v1/dcs/flights/{flight}/documents/manifest` | dcs.access | FlightDocumentController@manifest |
| POST | `/api/v1/dcs/flights/{flight}/groups` | dcs.checkin | GroupController@store |
| POST | `/api/v1/dcs/flights/{flight}/open` | dcs.checkin | FlightController@open |
| GET | `/api/v1/dcs/flights/{flight}/passengers` | dcs.access | PassengerController@index |
| POST | `/api/v1/dcs/flights/{flight}/passengers` | dcs.checkin | PassengerController@store |
| GET | `/api/v1/dcs/flights/{flight}/seat-map` | dcs.access | FlightController@seatMap |
| GET | `/api/v1/dcs/flights/{flight}/ssr-codes` | dcs.access | FlightController@ssrCodes |
| GET | `/api/v1/dcs/flights/{flight}/statistics` | dcs.access | FlightController@statistics |
| PUT | `/api/v1/dcs/passengers/{passenger}` | dcs.checkin | PassengerController@update |
| GET | `/api/v1/dcs/passengers/{passenger}/activity` | dcs.access | PassengerController@activity |
| GET | `/api/v1/dcs/segments/{segment}` | dcs.access | PassengerController@show |
| POST | `/api/v1/dcs/segments/{segment}/baggage` | dcs.checkin | BaggageController@store |
| POST | `/api/v1/dcs/segments/{segment}/baggage/quote` | dcs.access | BaggageController@quote |
| GET | `/api/v1/dcs/segments/{segment}/baggage-rules` | dcs.access | BaggageController@rules |
| GET | `/api/v1/dcs/segments/{segment}/boarding-pass` | dcs.access | CheckinController@boardingPass |
| DELETE | `/api/v1/dcs/segments/{segment}/check-in` | dcs.checkin | CheckinController@destroy |
| POST | `/api/v1/dcs/segments/{segment}/check-in` | dcs.checkin | CheckinController@store |
| DELETE | `/api/v1/dcs/segments/{segment}/group` | dcs.checkin | GroupController@destroy |

## Gate (boarding)

| Method | URI | Permission | Action |
|---|---|---|---|
| GET | `/api/v1/gate/flights` | gate.access | GateController@flights |
| GET | `/api/v1/gate/flights/{flight}` | gate.access | GateController@show |
| POST | `/api/v1/gate/flights/{flight}/board` | gate.access | GateController@board |
| GET | `/api/v1/gate/flights/{flight}/passengers` | gate.access | GateController@passengers |
| POST | `/api/v1/gate/segments/{segment}/offload` | gate.access | GateController@offload |
