---
title: 'Story 9.4: Backup, DR, and load testing'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'cb0f1eb3696c75c07943667e81ab1ff0f2c0ffe0'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-9-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no documented backup/restore path, DR targets, or repeatable load-test evidence for PRD SLOs — production recovery and performance validation are ad hoc.

**Approach:** Ship Docker Compose–aware MySQL backup/restore scripts, a disaster-recovery runbook with RTO/RPO, and k6 smoke load tests for liveness, auth, and product search against 9.2 latency targets.

## Boundaries & Constraints

**Always:**
- Scripts under `scripts/ops/` (bash, `set -euo pipefail`); work from repo root with `docker compose`.
- Backups written to `backups/mysql/` (gitignored); gzip SQL dumps with timestamp filenames.
- `docs/disaster-recovery.md` — RTO/RPO, backup schedule guidance, restore drill checklist, post-restore verification.
- `load-tests/k6/smoke.js` + `load-tests/README.md` — health, login, authenticated product search; thresholds align with `config/performance.php` (`SEARCH_P95_MS`, error rate).
- PHPUnit artifact test asserts scripts/docs/k6 files exist.
- Ops manual cross-links DR + load-test section.

**Ask First:**
- Automated off-site backup (S3) — default **defer** (document manual copy).
- CI k6 gate — default **defer** (manual/scheduled run documented).
- Prometheus/Grafana compose stack — default **defer** (9.3 metrics scrape only).

**Never:**
- Commit real backup dumps or secrets.
- Destructive restore without explicit `--force` flag.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Backup | Stack running | `.sql.gz` in `backups/mysql/` | Exit non-zero on dump failure |
| Restore dry | Missing file | Usage error | No DB change |
| Restore | Valid dump + `--force` | DB restored | Confirm prompt without `--force` |
| k6 smoke | Seeded dev user | p95 search under threshold | Non-zero exit on threshold breach |

</frozen-after-approval>

## Tasks & Acceptance

**Execution:**
- [x] `scripts/ops/backup-mysql.sh`, `restore-mysql.sh`
- [x] `docs/disaster-recovery.md`, `backups/mysql/.gitignore`
- [x] `load-tests/k6/smoke.js`, `load-tests/README.md`
- [x] PHPUnit artifact test; ops manual update

**Acceptance Criteria:**
- Given running Compose stack, when backup script runs, then a gzip dump is created
- Given DR doc, when followed, then RTO/RPO and restore steps are actionable
- Given k6 README steps against local stack, when smoke runs, then health/login/search execute with thresholds defined

## Verification

**Result (2026-08-29):** 522 passed / 3253 assertions (4 new backup/DR artifact tests).

## Suggested Review Order

**Ops scripts**

- MySQL backup via Compose `mysqldump`
  [`backup-mysql.sh:1`](../../scripts/ops/backup-mysql.sh#L1)

- Restore with confirm gate + `--force`
  [`restore-mysql.sh:1`](../../scripts/ops/restore-mysql.sh#L1)

**Runbook and load tests**

- DR targets, schedule, restore checklist
  [`disaster-recovery.md:1`](../../docs/disaster-recovery.md#L1)

- k6 smoke thresholds (health, login, product search)
  [`smoke.js:1`](../../load-tests/k6/smoke.js#L1)

## Spec Change Log

- 2026-08-29 — Backup/restore scripts, DR runbook, k6 smoke suite, artifact tests.
