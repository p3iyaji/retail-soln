---
title: 'Story 9.3: Observability (Sentry, metrics)'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '3739b8e81f7278c7470ae1d304193cdd43d81504'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-9-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Production has no error tracking, request correlation, queue/slow-query visibility, or scrapeable metrics — ops cannot detect payment/webhook/sync failures or validate 9.2 performance targets in the field.

**Approach:** Add optional Sentry (DSN-gated), structured JSON logging with `X-Request-Id`, Prometheus text metrics endpoint (token-gated), readiness health (DB + Redis), and global listeners for failed queue jobs and slow queries; emit structured operational logs at payment webhook and sync failure boundaries.

## Boundaries & Constraints

**Always:**
- `sentry/sentry-laravel` — active only when `SENTRY_LARAVEL_DSN` set; tests run with DSN unset (no outbound calls).
- `config/observability.php` — slow-query threshold, metrics toggle/token, log JSON toggle.
- Middleware: assign/propagate `X-Request-Id`; share `request_id` into log context; record HTTP request metrics (count + duration histogram).
- `GET /api/v1/metrics` — Prometheus text format; requires `Authorization: Bearer {OBSERVABILITY_METRICS_TOKEN}` when token configured; 404 when metrics disabled.
- `GET /api/v1/health/ready` — checks DB + Redis; 200 vs 503; exempt from API throttle like liveness.
- `Queue::failing` + `DB::listen` (when enabled) — structured `Log::warning/error` with domain tags; Sentry capture on job failure when DSN set.
- Structured logs at: payment webhook handler failures, sync push batch failures (existing paths).
- Feature tests: correlation header, metrics auth, ready health, listeners registered; no live Sentry HTTP.
- Ops manual section for Sentry/metrics env vars.

**Ask First:**
- Frontend Sentry (Admin/POS/portal) — default **defer**.
- OpenTelemetry tracing — default **defer** (metrics endpoint is Prometheus-compatible stub).
- Grafana/Prometheus docker services — default **defer to 9.4** ops doc.

**Never:**
- Commit secrets or live Sentry DSNs.
- Block POS/checkout on metrics emission or Sentry network I/O.
- Log full payment webhook payloads with PII (9.1 deferred retention still applies).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Request without ID | Any API call | Response includes new `X-Request-Id` | N/A |
| Metrics disabled | GET /metrics | 404 | No leak |
| Metrics bad token | Wrong bearer | 401 | No metrics body |
| Ready probe | DB up, Redis up | 200 `ready` | N/A |
| Ready probe | DB down | 503 | JSON error |
| Slow query | Query > threshold | `db.slow_query` warning log | Does not throw |
| Queue job fails | Job throws | `queue.job_failed` log + Sentry if DSN | Job still in failed table |

</frozen-after-approval>

## Code Map

- `backend/bootstrap/app.php` — register observability middleware
- `backend/routes/api.php` — health/ready, metrics routes
- `backend/config/logging.php` — optional JSON formatter channel
- New: `backend/config/observability.php`, `backend/config/sentry.php`
- New: `backend/app/Providers/ObservabilityServiceProvider.php`
- New: middleware, `MetricsController`, `HealthController`, `PrometheusRegistry`
- Wire: `PaymentWebhookController`, sync push failure paths
- Tests: `ObservabilityApiTest`, `ObservabilityListenersTest`

## Tasks & Acceptance

**Execution:**
- [x] Install/configure Sentry Laravel SDK (DSN optional)
- [x] Observability config, provider, correlation middleware, metrics registry
- [x] Health ready + metrics endpoints
- [x] Queue failure + slow-query listeners; webhook/sync structured logs
- [x] Tests + ops manual + `.env.example`

**Acceptance Criteria:**
- Given any `/api/v1/*` response, when inspected, then `X-Request-Id` header is present
- Given metrics enabled with token, when scraped with valid bearer, then Prometheus text is returned
- Given `/api/v1/health/ready` with healthy stack, when called, then status is 200
- Given a failing queue job in tests, when processed, then structured failure log is written
- Given full test suite, when run without Sentry DSN, then all tests pass with no outbound telemetry

## Verification

**Commands:**
- `cd backend && composer require sentry/sentry-laravel` (once)
- `cd backend && php artisan test --filter=Observability`
- `cd backend && php artisan test`

**Result (2026-08-29):** 518 passed / 3236 assertions (9 new observability tests).

## Suggested Review Order

**Correlation and health**

- Request ID middleware + log context
  [`AssignRequestCorrelationId.php:1`](../../backend/app/Http/Middleware/AssignRequestCorrelationId.php#L1)

- Liveness vs readiness probes
  [`HealthController.php:1`](../../backend/app/Http/Controllers/Api/HealthController.php#L1)

**Telemetry**

- Queue failure + slow-query listeners
  [`ObservabilityServiceProvider.php:1`](../../backend/app/Providers/ObservabilityServiceProvider.php#L1)

- Token-gated Prometheus metrics
  [`MetricsController.php:1`](../../backend/app/Http/Controllers/Api/MetricsController.php#L1)

## Spec Change Log

- 2026-08-29 — Implemented Sentry SDK (optional DSN), correlation IDs, metrics, readiness, listeners.
