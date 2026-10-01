# api — Michango ya Ustawi wa Jamii

Laravel 13 REST API for the welfare contribution system of **Benjamin William Mkapa Sekondari**.
It serves the Flutter app (Windows + Android), which works offline on SQLite and syncs to this API (MySQL).

## Setup

```bash
composer install
cp .env.example .env            # set DB_*, ADMIN_*, SMS_*, MAIL_*
php artisan key:generate
php artisan migrate --seed      # tables + first admin (ADMIN_EMAIL / ADMIN_PASSWORD)
php artisan michango:import "path/to/ustawi_michango_2026.xlsx"
php artisan serve --host=0.0.0.0 --port=8000
```

MySQL (WAMP): create the `michango_ustawi` database (utf8mb4). The connection uses InnoDB.

## Main endpoints (`/api`, Sanctum bearer token)

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/login` | Get a token |
| GET | `/sync/pull?since=` | Changes since the last pull (teachers, contributions, settings) |
| POST | `/sync/push` | Upsert offline changes by UUID; notifies teachers |
| GET | `/dashboard`, `/summary/monthly` | Totals computed by `ContributionLedger` |
| GET/POST/PUT/DELETE | `/teachers`, `/contributions` | CRUD (409 on a duplicate month unless `mode=additional`) |
| POST | `/import/preview`, `/import/commit` | Excel import with validation and duplicate handling |
| GET/PUT | `/settings`, plus `/users` and `/notifications` | Administration |

## Notifications

When a contribution is recorded (directly or through sync), the teacher receives an SMS and/or email:

- **SMS:** set `SMS_DRIVER` to `log`, `beem` or `africastalking`.
- **Email:** set `MAIL_MAILER`.

Every attempt is stored in `notification_logs`.

## Tests

```bash
php artisan test
```
