<!-- bmad:context -->
<!-- Verified 2026-08-24 against unborn main (no commits). Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## retail-soln

Multi-store offline-first POS and retail management platform. Stack: Laravel 13 modular monolith (nWidart) under `backend/` + Vue 3 SPAs (Admin/SM, POS PWA) over `/api/v1` — **no Inertia**. Planning lives in `_bmad-output/planning-artifacts/`; source brief in `docs/proposal.md`. **Epic 3 in progress** — Stories 3.1–3.3 done; **next: Story 3.4** supplier invoices and payments — see `_bmad-output/planning-artifacts/epics.md`.

## Policy

- Start application code only for the current sprint story (Epic 1+). Follow `_bmad-output/handover-epic-1.md` when beginning implementation.
- Follow architecture invariants in `_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md` (AD-1–AD-15). On conflict with older prose in `docs/proposal.md`, the spine and PRD win (notably: **no Inertia**).
- Never mutate inventory quantity except via the inventory ledger service (AD-2). Never use floats for money — `DECIMAL` only.
- Never commit secrets, `.env`, or live payment credentials. Payment webhooks must verify signatures and be idempotent.
- Prefer smallest coherent change; do not drive-by refactor unrelated modules.

## Where things are

- Proposal / raw intent: `docs/proposal.md`
- PRD + addendum: `_bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/`
- Architecture + Epic 0 companions (ERD, API, sync, security, permissions): `_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/`
- Epics/stories: `_bmad-output/planning-artifacts/epics.md`
- UX spines (before POS UI): `_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/DESIGN.md` and `EXPERIENCE.md`
- Sprint tracking: `_bmad-output/implementation-artifacts/sprint-status.yaml`
- Session handover for Epic 1: `_bmad-output/handover-epic-1.md`
- Layout: `backend/` (Laravel + `Modules/`), `frontend/admin/` (Vue SPA), `frontend/pos/` (placeholder until Epic 4), `docker/`, root `docker-compose.yml`
- Modules: Authentication, Organization, Settings, Audit, Products, Inventory, Purchasing
- Admin SPA: `frontend/admin/` (Vue 3 + Vite + TS + Tailwind); POS placeholder until Epic 4

## Running and verifying

- First boot (host): `cd backend && composer install && cp -n .env.example .env && php artisan key:generate` (Compose does **not** require `backend/.env`; it injects MySQL/Redis via service `environment`).
- Docker stack (from repo root): `docker compose up -d --build` — services: `mysql`, `redis`, `app` (PHP-FPM), `nginx` (http://localhost:8080), `queue` (`php artisan queue:work`). Validate with `docker compose config`. Smoke: `curl -sf http://localhost:8080/api/v1/health`.
- Backend tests (Herd PHP 8.4, from `backend/`): `composer install` then `php artisan test` (uses sqlite `:memory:` via `phpunit.xml`).
- API routes: `cd backend && php artisan route:list --path=api/v1` — expect `/health`, `/auth/*`, org/stores, RBAC, settings/audit, catalog `/products`…, inventory, `/suppliers`, `/purchase-orders`, and `POST /goods-receipts`.
- Seed RBAC + dev users: `cd backend && php artisan db:seed` (permission matrix + role maps).
- **Dev login** (after seed): `admin@retail.local` / `password` (org admin); `inventory@retail.local` / `password` (products/receive); `procurement@retail.local` / `password` (suppliers/POs). Start API: `cd backend && php artisan serve --port=8001` (avoid `:8000` if another Herd site uses it). Admin SPA: `cd frontend/admin && npm run dev` → http://localhost:5173 (`VITE_PROXY_TARGET` in `frontend/admin/.env`; use `:8080` for Docker nginx).
- Auth smoke: `curl -sf -X POST http://localhost:8001/api/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"admin@retail.local","password":"password"}'`
- Admin SPA: `cd frontend/admin && npm install && npm run dev` (http://localhost:5173). Build: `npm run build`.
- Local without Docker: sqlite default in `.env.example` is fine for unit/feature tests.
- **Current story:** Epic 3 Story 3.4 — supplier invoices and payments. Never mutate `inventory_balances` quantities outside `InventoryLedgerService` (AD-2).

## Conventions that differ from defaults

- Frontends are Vue 3 SPAs consuming Sanctum Bearer tokens — not Livewire, not Inertia, not Blade-driven app UI.
- Store-scoped API calls send `X-Store-Id`; authorization is server-side Policies (AD-4).
- Offline/client-created sales use client UUIDs as idempotency keys (AD-5); Phase 6 oversell accepts negative stock with manager approval (AD-9).
- Payments: manual + Stripe + Paystack behind one abstraction (AD-10). Installment plans are first-class AR schedules (AD-14), not informal partial balances. Proforma invoices are non-fiscal until converted (AD-15) — separate from tax-invoice drafts. Receipt hardware: ESC/POS port (AD-13).
- POS UI must follow UX spines (sync badge always visible; F2/F4/F8/F9/Esc shortcuts).

## Known pitfalls

- Proposal §1 still lists Inertia — ignore it; line 77 and AD-1 forbid Inertia.
- Do not implement POS UI before foundation epics; Epic 4 depends on pricing/inventory APIs and UX spines.
- Gift cards are deferred (OQ-1); loyalty is a rules engine (AD-11), not a dead customer field.

<!-- /bmad:context -->
