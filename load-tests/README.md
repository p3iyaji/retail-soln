# Load tests (k6)

Smoke load tests for retail-soln Story 9.4. Validates liveness, readiness, staff login, and product search under modest concurrent load.

## Prerequisites

- [k6](https://k6.io/docs/get-started/installation/) installed locally
- Docker Compose stack running (`docker compose up -d`)
- Database seeded (`cd backend && php artisan db:seed`)

## Run

From the repository root:

```bash
k6 run load-tests/k6/smoke.js
```

### Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `BASE_URL` | `http://localhost:8080` | API base (nginx) |
| `SEARCH_P95_MS` | `200` | Product search p95 threshold (ms) — matches `config/performance.php` |
| `ADMIN_EMAIL` | `admin@retail.local` | Staff login for search auth |
| `ADMIN_PASSWORD` | `password` | Dev seed password |

Example with custom target:

```bash
BASE_URL=http://staging.example.com SEARCH_P95_MS=250 k6 run load-tests/k6/smoke.js
```

## Thresholds

The script fails (non-zero exit) when:

- More than 1% of HTTP requests fail
- Health p95 latency exceeds 500ms
- Readiness p95 exceeds 1000ms
- Product search p95 exceeds `SEARCH_P95_MS`

Use results as release evidence alongside `php artisan test`. For sustained load or soak tests, extend stages in `smoke.js` or add new scenarios under `load-tests/k6/`.

## CI note

k6 is not wired into GitHub Actions in v1 — run manually or on a schedule in your ops environment. See `docs/disaster-recovery.md` for when to run after restore.
