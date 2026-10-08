# FlightMode API — Architecture & Conventions

Laravel 13, API only, MySQL. Frontend (React + shadcn + TanStack) talks to it with a Sanctum Bearer token.

## Folder structure

```
app/
├── Enums/                      String-backed enums for every status / type column
├── Exceptions/                 BusinessRuleException (422 with a machine-readable code)
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Auth/               login, logout, me
│   │   ├── Admin/              back-office (airline / system admins)
│   │   ├── Agency/             travel agency portal
│   │   ├── Dcs/                check-in counter
│   │   ├── Gate/               boarding app
│   │   ├── Checkin/            public online check-in (no login)
│   │   └── Partner/            agency machine-to-machine API (token ability `partner`)
│   ├── Requests/<Area>/        FormRequest per write action
│   ├── Resources/              JsonResource per model (snake_case keys)
│   └── Middleware/
├── Models/                     Eloquent models only: relations, casts, scopes. No business logic.
├── Services/<Domain>/          Business logic. Controllers call services, services call models.
│   ├── Access/                 AccessScope: which airline / agency / airport a user may see
│   ├── Allocation/             agency seat allocations & availability
│   ├── Baggage/                baggage acceptance, bag tag numbers
│   ├── Booking/                bookings, passenger rules, pricing, PNR / ticket numbers, import
│   ├── Checkin/                check-in, seat assignment, boarding, boarding pass
│   ├── Flight/                 series generation, flight operations, statistics
│   ├── Seat/                   seat map generation & availability
│   ├── Documents/              e-ticket PDF, PNL, manifests
│   ├── Integrations/Chinggis/  push allocations to Chinggis agent API
│   └── ActivityLogger.php
├── Exports/                    Excel / CSV documents (extend Support/Spreadsheet/TableExport)
├── Mail/
└── Support/                    PassengerAge, Spreadsheet/ (PhpSpreadsheet 5 export base + reader)
routes/
├── api.php                     mounts routes/api/v1/*.php under /api/v1
└── api/v1/{auth,admin,agency,dcs,gate,checkin,partner}.php
```

## Rules

1. **Thin controllers.** Validate with a FormRequest, call one service method, return a Resource.
2. **One place per rule.** Age bands (INF < 2, CHD < 12), passport validity, cabin quota, seat availability,
   bag tag numbering, BCBP barcode … each lives in exactly one service. The legacy app had the same logic
   copied into 3–4 controllers; here it must not be duplicated.
3. **Access scope.** Every list / show query goes through `AccessScope` (airline user → own airline,
   agency user → own agency, airport user → own station, system admin → everything).
4. **Permissions.** Route middleware `can:<permission.key>`. Keys are defined in `App\Enums\PermissionKey`
   and seeded by `PermissionSeeder`.
5. **Responses.** JSON, snake_case. Single object → `{ "data": {...} }`; list → `{ "data": [...], "links", "meta" }`;
   action → `{ "message": "...", "data": {...} }`. Errors → `{ "message": "...", "code": "...", "errors": {...} }`.
6. **Business errors** throw `BusinessRuleException::make('code', 'Human message')` → HTTP 422.
7. **Dates.** Stored in UTC. Resources return ISO-8601 UTC plus `*_local` fields in the airport's timezone.
8. **Audit.** State changes that the legacy app logged go through `ActivityLogger` (`activity_logs` table).
9. **Transactions.** Any service method that writes more than one row runs in `DB::transaction`.
10. **Tests.** Feature tests per area under `tests/Feature/<Area>`, SQLite in-memory.

## Tokens

- **Login token** (`POST /auth/login`): ability `*`. Used by the React app for every area except `/partner`.
- **Partner token** (`POST /admin/users/{user}/api-token`): ability `partner`, only for agency users whose agency
  has `api_enabled`. Accepted only by `/api/v1/partner/*` (`partner` middleware); every other authenticated route
  rejects it (`no-partner-token` middleware). Bookings made with it get `source = agency_api`.
- **Online check-in session**: not a Sanctum token. `/checkin/lookup` → `/checkin/verification-code` (e-mailed
  4-digit code) → `/checkin/verify` returns `session_token`, sent as `X-Checkin-Token`.
