# FlightMode API

Backend of FlightMode v3 — Laravel 13, **API only**, MySQL, Sanctum Bearer tokens.
Replaces the legacy monolith `flightmodev2` (not modified). The React frontend lives next to it in `flightmodev3`.

## Setup

```bash
composer install
cp .env.example .env          # set DB_*, MAIL_*, ADMIN_EMAIL / ADMIN_PASSWORD, CHINGGIS_*
php artisan key:generate
php artisan migrate --seed    # permissions, roles, countries, SSR codes, sample airports, admin user
php artisan serve
```

Run tests (SQLite in memory): `php artisan test`. Code style: `vendor/bin/pint`.

## Areas

| Prefix | Who | Auth |
|---|---|---|
| `/api/v1/auth` | everyone | login → Bearer token |
| `/api/v1/admin` | system / airline / station users | token + `can:` permission |
| `/api/v1/agency` | travel agency portal | token + `agency.portal` |
| `/api/v1/dcs` | check-in counter | token + `dcs.access` / `dcs.checkin` |
| `/api/v1/gate` | boarding app | token + `gate.access`, assigned flights only |
| `/api/v1/checkin` | passengers (online check-in) | e-mail code → `X-Checkin-Token` |
| `/api/v1/partner` | agency systems (machine-to-machine) | partner token |

## Docs

- `docs/architecture.md` — folder structure, conventions, tokens
- `docs/database.md` — schema and legacy → new table mapping
- `docs/legacy-mapping.md` — where each legacy business rule lives now, intentional differences, test coverage
- `docs/api-routes.md` — all 218 routes with their permissions (generated from `route:list`)
