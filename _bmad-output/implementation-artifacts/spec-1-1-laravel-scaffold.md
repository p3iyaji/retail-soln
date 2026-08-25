---
title: '1.1 Laravel modular monolith scaffold'
type: 'feature'
created: '2026-08-25'
status: 'done'
baseline_commit: 'NO_COMMITS'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The repo has planning only — no Laravel app, Docker stack, modular boundaries, or CI — so later auth/org/RBAC stories have nowhere to land.

**Approach:** Scaffold a Laravel 13 API app under `backend/` with nWidart modules, Docker (MySQL, Redis, Nginx, app, queue), `/api/v1` + Sanctum middleware group, Organization and Authentication module stubs, and GitHub Actions PHPUnit on push. No Inertia; no Vue/POS/inventory yet.

## Boundaries & Constraints

**Always:**
- API-first Vue SPA clients later; Laravel serves `/api/v1` only (no Inertia, no Blade-driven app UI).
- Layout: `backend/` (Laravel + `Modules/`), `docker/`, placeholder dirs `frontend/admin/` and `frontend/pos/` (empty `.gitkeep` OK).
- Pin Laravel 13.x + PHP 8.4; `nwidart/laravel-modules` ^13; install `laravel/sanctum`.
- Money never as float (DECIMAL habit even if unused yet).
- Commit no secrets; `.env.example` only.

**Ask First:**
- Switching CI host away from GitHub Actions.
- Relocating app outside `backend/` / changing module package layout away from nWidart.
- Adding Fortify login endpoints, org/store CRUD, or Vue admin (those are Stories 1.2–1.6).

**Never:**
- Inertia, Livewire, or server-rendered SPA shells.
- POS UI, inventory ledger, products, purchasing, customer portal.
- Implementing auth login/token issuance, RBAC seed, or admin SPA (stub modules/routes only).
- Inventing domain migrations beyond what module stubs need to boot (users table from Laravel default is fine).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Health / API mount | GET `/api/v1/health` (or equivalent stub) unauthenticated | 200 JSON proving `/api/v1` is mounted | N/A |
| Sanctum group wired | Authenticated route stub under `/api/v1` without Bearer token | 401 Unauthorized | Laravel Sanctum default |
| Module stubs load | App boots / `php artisan about` or route:list | Organization + Authentication modules registered; no fatal | Fix module service providers / merge-plugin if missing |
| CI on push | Push to GitHub with PHPUnit job | Job installs deps, runs tests, exits 0 | Fail the push check on test failure |

</frozen-after-approval>

## Code Map

- _(greenfield)_ no `backend/`, `docker/`, or `frontend/` yet — create per spine structural seed
- `AGENTS.md` — Running and verifying still TODO; refresh after scaffold with real compose/test commands (post-story or follow-up)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — set `epic-1` / `1-1-laravel-scaffold` in-progress → done
- `_bmad-output/implementation-artifacts/epic-1-context.md` — Epic 1 constraints (API-first, layout, no POS/inventory)
- Spine AD-1 / AD-8 — no Inertia; nWidart modular boundaries (~15–20 modules later; only Org + Auth stubs now)
- Target layout after scaffold:
  - `backend/` — Laravel 13 app; `routes/api.php` mounts `/api/v1`
  - `backend/Modules/Organization/` — stub module (provider + empty/health route OK)
  - `backend/Modules/Authentication/` — stub module (Sanctum-ready; no login yet)
  - `docker/` — compose + Nginx/PHP Dockerfiles as needed
  - `.github/workflows/ci.yml` — PHPUnit on push
  - `frontend/admin/.gitkeep`, `frontend/pos/.gitkeep` — placeholders only

## Tasks & Acceptance

**Execution:**
- [x] `backend/` -- Create Laravel 13 project (PHP 8.4), configure as API-centric app, no Inertia -- foundation for all modules
- [x] `backend/composer.json` + modules -- Require `nwidart/laravel-modules` ^13 and `laravel/sanctum`; publish configs; enable composer-merge-plugin for `Modules/*/composer.json` -- modular monolith substrate
- [x] `backend/Modules/Organization` + `backend/Modules/Authentication` -- Generate module stubs with service providers registered -- Story AC module stubs
- [x] `backend/routes/api.php` (and/or module route files) -- Mount `/api/v1` group; apply Sanctum auth middleware to protected subgroup; add unauthenticated health stub + one protected stub that 401s without token -- prove API + Sanctum wiring
- [x] `docker/` + root `docker-compose.yml` -- Services: MySQL 8, Redis, Nginx, app (PHP-FPM), queue worker -- Story AC Docker stack
- [x] `.env.example` (backend) -- Document DB/Redis/queue vars matching compose; never commit `.env` -- safe local boot
- [x] `.github/workflows/ci.yml` -- On push: PHP 8.4, composer install, PHPUnit -- Story AC CI
- [x] `frontend/admin/.gitkeep` + `frontend/pos/.gitkeep` -- Placeholder dirs only -- match intended monorepo layout without building SPAs
- [x] `backend/tests/...` -- Minimal feature test(s) for health 200 and protected route 401 -- CI has something real to run
- [x] `_bmad-output/implementation-artifacts/sprint-status.yaml` -- `epic-1: in-progress`; `1-1-laravel-scaffold` → `in-progress` then `done` -- tracking
- [x] `AGENTS.md` Running and verifying -- Fill real `docker compose` / `php artisan test` commands once known -- agent runbook

**Acceptance Criteria:**
- Given architecture approved and empty app tree, when scaffold is created, then Docker Compose defines MySQL, Redis, Nginx, app, and queue worker services that can be brought up for local/dev use
- Given the app is running, when routes are listed or hit, then `/api/v1` exists with Sanctum middleware on protected routes
- Given modules are installed, when the app boots, then Organization and Authentication module stubs exist and are registered
- Given a push to the repo, when CI runs, then PHPUnit executes (and passes for the scaffold tests)

## Spec Change Log

## Design Notes

- Prefer official `laravel new` / `composer create-project laravel/laravel` into `backend/` then strip any default frontend SPA scaffolding that conflicts with AD-1; do not install Breeze/Jetstream with Inertia.
- Sanctum in 1.1 = package installed + middleware group wired; Fortify login/token APIs land in Story 1.2.
- Module names: `Organization` and `Authentication` (match epics/handover). Stores CRUD is Story 1.3 — do not add a full Stores module unless artisan stub is trivial; avoid domain migrations for stores/orgs until 1.3.
- Nginx should proxy to PHP-FPM app container; queue worker runs `php artisan queue:work` (or equivalent) against Redis.

## Verification

**Commands:**
- `docker compose config` -- expected: validates; lists mysql, redis, nginx, app, queue (names may vary slightly)
- `cd backend && php artisan route:list --path=api/v1` -- expected: health + protected stub listed; Sanctum middleware on protected
- `cd backend && php artisan test` -- expected: exit 0
- `rg -i inertia backend/` -- expected: no Inertia dependency in composer.lock / code

**Manual checks (if no CLI):**
- Confirm `frontend/admin` and `frontend/pos` exist as placeholders only (no Vue app yet)
- Confirm no inventory/POS domain modules created

## Suggested Review Order

**API mount**

- `/api/v1` health + Sanctum-protected `/me` stubs
  [`api.php:6`](../../backend/routes/api.php#L6)

- Sanctum token trait on the User model
  [`User.php:19`](../../backend/app/Models/User.php#L19)

**Modules**

- Organization registers API routes only (no Blade web map)
  [`RouteServiceProvider.php:25`](../../backend/Modules/Organization/app/Providers/RouteServiceProvider.php#L25)

- Authentication module stub metadata
  [`module.json:1`](../../backend/Modules/Authentication/module.json#L1)

- nWidart + Sanctum + merge-plugin for `Modules/*`
  [`composer.json:14`](../../backend/composer.json#L14)

**Docker**

- Compose services: mysql, redis, app, nginx, queue (no required `.env` file)
  [`docker-compose.yml:1`](../../docker-compose.yml#L1)

- PHP 8.4-FPM image with MySQL + Redis extensions
  [`Dockerfile:1`](../../docker/php/Dockerfile#L1)

- Nginx proxies to `app:9000`
  [`default.conf:14`](../../docker/nginx/default.conf#L14)

**CI & tests**

- GitHub Actions PHPUnit on push/PR
  [`ci.yml:8`](../../.github/workflows/ci.yml#L8)

- Health, 401, Sanctum actingAs, module health coverage
  [`ApiV1ScaffoldTest.php:10`](../../backend/tests/Feature/ApiV1ScaffoldTest.php#L10)
