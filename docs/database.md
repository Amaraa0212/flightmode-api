# FlightMode v3 — Database schema

Цэвэр шинэ схем. Хуучин `fmd` өгөгдлийг ашиглахгүй.

## Зарчим
- Хүснэгт: snake_case, олон тоо. Pivot: ганц тоо, цагаан толгойн дарааллаар (`baggage_rule_flight`).
- PK бүгд `bigint unsigned` (`id()`), FK бүгд `foreignId()->constrained()`.
  - Эзэмшигдэх хүүхэд → `cascadeOnDelete`, бизнесийн холбоос → `restrictOnDelete`, `created_by` → `nullOnDelete`.
- Мөнгө `decimal(12,2)` + `currency char(3)` (default MNT). Жин `decimal(6,1)`.
- Статус, төрөл: `string` багана + `app/Enums/*` (PHP backed enum). MySQL enum ашиглахгүй.
- Огноо цаг: **UTC**-ээр хадгална (`dateTime`). Орон нутгийн цагийг `airports.timezone`-оор хөрвүүлнэ.
- CSV утга хадгалахгүй — бүгд мөр/pivot.

## Хүснэгтүүд (migration дарааллаар)
| Migration | Хүснэгтүүд |
|---|---|
| reference | `countries`, `airports`, `ssr_codes` |
| organization | `airlines`, `agencies`, `agency_airline` |
| access_control | `roles`, `permissions`, `permission_role`, `users`, `password_reset_tokens`, `sessions` |
| personal_access_tokens | Sanctum (admin/DCS/gate app login + agency API token) |
| airline_config | `airline_ssrs`, `baggage_rules`, `baggage_policies`, `fares`, `seat_prices` |
| aircraft | `aircraft`, `aircraft_cabins`, `aircraft_seats`, `aircraft_load_zones`, `seat_assignment_profiles`, `seat_assignment_profile_zones`, `service_templates` (+2 pivot) |
| flight | `flight_series`, `flights`, `baggage_rule_flight`, `flight_seat_price`, `flight_seat_blocks`, `flight_gate_agents` |
| agency_allocation | `agency_allocations`, `agency_allocation_quotas` |
| booking | `bookings`, `passengers`, `checkin_groups`, `passenger_segments`, `passenger_segment_ssr`, `prepaid_baggage`, `baggage_items` |
| payment | `orders`, `order_items` |
| misc | `announcements`, `announcement_dismissals`, `charter_requests`, `airline_charter_request`, `activity_logs` |

## Гол бүтэц
```
airlines ─┬─ aircraft ─┬─ aircraft_cabins / aircraft_seats / aircraft_load_zones
          │            └─ seat_assignment_profiles ── seat_assignment_profile_zones
          ├─ flight_series ── flights ─┬─ flight_seat_blocks / flight_gate_agents
          │                            └─ baggage_rule_flight / flight_seat_price (online check-in)
          ├─ fares, baggage_rules, seat_prices, airline_ssrs
          └─ agency_airline ── agencies ── agency_allocations ── agency_allocation_quotas

bookings (PNR) ── passengers ── passenger_segments (1 зорчигч × 1 нислэг)
                                  ├─ passenger_segment_ssr
                                  ├─ baggage_items / prepaid_baggage
                                  └─ order_items ── orders
```

## Хуучин → шинэ харгалзаа
| Хуучин (fmd) | Шинэ | Тэмдэглэл |
|---|---|---|
| `iata`, `airports` | `airports` | timezone-той, IATA код unique |
| `country_code` | `countries` | |
| `ssrs` / `ssr_permissions` | `ssr_codes` / `airline_ssrs` | |
| `airlines` (+ airplanes.codeIATA/numberIATA, `bag_tag_positions`, carriage) | `airlines` | IATA/numeric код агаарын тээврийн компани дээр шилжсэн |
| agent = `users` + `agent_airline`, sub-agent = `users.agent_id` | `agencies` + `agency_airline`, `users.agency_id` | Агентлаг тусдаа байгууллага болсон |
| `users.roleID/company_id/airport/api_token` | `users.role_id/airline_id/airport_id` + Sanctum token | `company_id` нь `airline_id`-тай нийлсэн |
| `airplanes` (+F/C/W/Y) | `aircraft` + `aircraft_cabins` | |
| `seatmaps` | `aircraft_seats` | seat_type: window/middle/aisle, side: left/middle/right |
| `airplane_rule` (zone, min, max) | `aircraft_load_zones` (zone, pax_from, pax_to) | Ачааллаас хамаарч нээх бүс |
| `seat_zone` (zone CSV, parent CSV) | `seat_assignment_profiles` + `_zones` (zone, priority) | |
| `seat_rule` | `seat_prices` | |
| `templates` (bag_id/seat_id CSV) | `service_templates` + pivot | |
| `scheduled_flights` | `flight_series` | Нислэгийн дугаар, чиглэл, онгоц series дээр |
| `schedules` | `flights` | status 0–4 → string enum; time1/2/3 → `off_block_at`, `takeoff_at`, `estimated_arrival_at` |
| `shop` | `flights.online_checkin_*` + `baggage_rule_flight`, `flight_seat_price` | |
| `seat_block` (CSV) | `flight_seat_blocks` | Суудал бүр нэг мөр |
| `gate_users` | `flight_gate_agents` | |
| `fares` (schedule="ULN-HVD", dateRange string) | `fares` (origin/destination FK, valid_from/to) | |
| `agent_schedule` (+F/C/W/Y, fare_ids CSV) / `agent_fare_quota` | `agency_allocations` / `agency_allocation_quotas` | |
| `passengers` (PNR, scheduleID/2, seat/2, tkt_status/_sch_2, oci_status/_two, sequence/_2 …) | `bookings` + `passengers` + `passenger_segments` | Олон leg дэмжинэ. tkt_status 0/1/2/3 → booked/checked_in/boarded/flown |
| `agent_payments` | `passenger_segments.fare_amount/tax_amount/agency_sale_amount` | |
| `passengers.group_id`, `baggages.group_id` | `checkin_groups` | |
| `baggages` | `baggage_items` | Crew baggage: `type=crew`, segment null |
| `airline_baggage` | `prepaid_baggage` | |
| `orders` / `order_details` | `orders` / `order_items` | |
| `notification_required` (+ session dismiss) | `announcements` + `announcement_dismissals` | |
| `charter_applications` | `charter_requests` + `airline_charter_request` | |
| `admin_action_logs`, `dcs_action_log`, `passenger_action_logs` | `activity_logs` | channel-аар ялгана |
| `fare`, `permission_role_old`, `oci_transaction`, `bag_tag`, `shop_items`, `password_resets` | — | Хасагдсан |

## Анхаарах
- `passenger_segments` дээр `unique(flight_id, seat_number)`. Segment цуцлах/устгах, offload хийхэд `seat_number`-ийг `null` болгоно (soft delete хийсэн мөр ч суудлыг түгжинэ).
- Хүүхэд/нярай үнэ: `fares.child_percent`, `infant_percent`. Зорчигчийн төрлийг (`ADT/CHD/INF`) нислэгийн өдрийн насаар app талд тооцоолж хадгална.
- Online check-in-ий email баталгаажуулах код cache-д хадгалагдана (хүснэгтгүй).
