# Epic 1 Context: Platform Foundation

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver the runnable platform substrate for all later epics: Laravel modular monolith with Docker, Sanctum/Fortify auth, single-organization multi-store structure, RBAC with store access, org settings, append-only audit, and a Vue 3 admin SPA shell. Without this foundation, catalog, inventory, and POS work cannot be secured or scoped correctly. Do not implement POS UI, inventory ledger, or customer portal here.

## Stories

- Story 1.1: Laravel modular monolith scaffold
- Story 1.2: Authentication with Fortify and Sanctum
- Story 1.3: Organization and store management
- Story 1.4: Users, roles, and permissions
- Story 1.5: Settings and audit infrastructure
- Story 1.6: Admin portal shell (Vue SPA)

## Requirements & Constraints

- One organization per deployment (not SaaS multi-tenant). Org owns stores and warehouses; each store has address, currency, and tax config.
- Users may access all stores, specific stores, or warehouses; cross-store data must be denied without authorization.
- Roles include Super Admin, Org Admin, Store Manager, Cashier, Inventory Manager, Procurement Officer, Accountant, Delivery Staff (Customer portal role later). Permissions use dot-notation (e.g. `sales.refund`) and are enforced server-side via Policies.
- Login returns a Sanctum bearer token; invalid credentials → 401; rate-limit login; 2FA available/required for privileged admin roles; revoke tokens on password reset or admin disable.
- Audit logs are append-only for sensitive creates/updates/deletes (user, action, entity, before/after, store, timestamp; IP where applicable). No API update/delete of audit rows.
- Org settings are manageable by authorized admins; admin portal covers org, stores, users, and settings foundations (later modules as stubs/nav only).
- Success bar for leaving Epic 1: Docker stack boots; migrations for org/store/user/RBAC/settings/audit; Sanctum auth works; permission matrix seeded with store-isolation policy tests; admin SPA logs in and shows shell nav.

## Technical Decisions

- **Stack:** PHP 8.4+, Laravel (pin at scaffold), MySQL 8+, Redis, Vue 3 + TypeScript + Vite + Tailwind 4. Layout: `backend/` (nWidart modules), `frontend/admin/`, `frontend/pos/` (later), `docker/` (MySQL, Redis, Nginx, app, queue).
- **API-first:** All UIs are Vue SPAs on `/api/v1`. No Inertia. Controllers delegate to application actions/services; modules communicate via services/events, not casual cross-module model access.
- **Auth:** Fortify for credentials/2FA flows; Sanctum personal access tokens for SPA. Store-scoped ops send `X-Store-Id`; validate against user store access (org-wide roles may omit or pass any store).
- **API conventions:** kebab-case URLs; camelCase JSON responses; errors `{ message, errors? }`; pagination `data` + `meta` (`current_page`, `last_page`, `total`); UTC / ISO 8601 dates; bigint PKs for server entities (UUIDs reserved for client/offline entities later).
- **Core endpoints (foundation):** `/auth/login|logout|me|two-factor/*`, `/organizations/current`, `/stores`, `/warehouses`, `/users` (+ roles/stores assign), `/roles`, `/permissions`, `/settings`, `/audit-logs`.
- **Data model seed:** `organizations`, `stores`, `warehouses`, `users`/`employees`, roles/permissions pivots, `store_user`, scoped `settings`, append-only `audit_logs`. Seed full permission matrix and default role maps in Story 1.4; tests must cover at least SM cannot read another store and cashier cannot refund.
- **Money:** DECIMAL only (no floats) — establish habit even if few money fields appear yet.

## UX & Interaction Patterns

- Admin and Store Manager share one Vue app with role-based navigation; POS is a separate app later.
- Shell needs: login (+ optional 2FA), top-bar store switcher when user has multiple stores (drives `X-Store-Id`), nav stubs for org/stores/users (and settings/audit as available). Hide nav items the user lacks permission for; deep-link → 403 with back.
- Admin visual stance: light dense workspace; content fluid to ~1440px; WCAG 2.2 AA target. No POS chrome/sync badge work in this epic.

## Cross-Story Dependencies

- Implement in order: 1.1 → 1.2 → 1.3/1.4 → 1.5 → 1.6 (admin shell needs working auth + at least stub APIs).
- Story 1.4 consumes the Epic 0 permission matrix as the seed source of truth.
- Epics 2–4 depend on this epic’s org/store isolation, Policies, and admin SPA shell; do not start inventory ledger or POS until Epic 1 is ready.
