# Browser E2E tests (Playwright)

Playwright exercises critical POS UI flows against the Vite dev server (port **5174**) with API requests proxied to Laravel.

## Prerequisites

- Node 20+
- Backend API running and seeded (see below)
- `npm install` in `e2e/` and `frontend/pos/`

## Quick smoke (login page only)

No API required — Vite serves the login shell:

```bash
cd e2e && npm install && npm run test:login
```

## Full critical path (login → store → floor + sync badge)

1. Prepare the API (sqlite is fine for CI/local):

```bash
cd backend
composer install
cp -n .env.example .env
php artisan key:generate
mkdir -p database && touch database/database.sqlite
php artisan migrate --force
php artisan db:seed
php artisan db:seed --class=E2ePlaywrightSeeder
php artisan serve --port=8001
```

2. In another terminal, run Playwright (starts POS Vite automatically):

```bash
cd e2e
E2E_API_URL=http://127.0.0.1:8001 npm test
```

Credentials: `admin@retail.local` / `password` (store **Demo Store** attached by `E2ePlaywrightSeeder`).

## CI

The GitHub Actions `e2e-pos` job runs login smoke plus the critical floor spec against sqlite + `artisan serve`.

## Offline sync regression

Server-side offline-sync scenarios live in `backend/tests/Feature/OfflineSyncScenarioTest.php`. POS queue/reconnect behaviour is covered by Vitest in `frontend/pos/src/sync/offlineReconnect.test.ts` and existing push/pull unit tests.
