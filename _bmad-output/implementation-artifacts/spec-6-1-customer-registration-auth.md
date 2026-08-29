---
title: 'Story 6.1: Customer registration and auth'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '1b86f766111e87402253c4e34ff43eef5c521082'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-6-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/security-design.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/EXPERIENCE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no customer-facing portal or customer login. Staff CRM `customers` records have no credentials, and staff Sanctum auth must not be reused for end customers.

**Approach:** Scaffold `frontend/customer-portal/` (Vue 3 SPA) and `backend/Modules/CustomerPortal/` with a **separate** portal authenticatable account linked to `customers`, portal-scoped Sanctum tokens/abilities, and register / login / logout / me / email verification / profile + address management. Catalogue, cart, and orders stay later stories.

## Boundaries & Constraints

**Always:**
- New Vue SPA at `frontend/customer-portal/` (Vite + TS + Tailwind + Sanctum Bearer) — no Inertia; no access to Admin/POS staff routes.
- Portal auth is a distinct identity from staff `User` (separate guard/provider or equivalent isolation). Tokens must not authorize staff `/auth/*` admin APIs; staff tokens must not authorize portal customer APIs.
- Register creates/links a `customers` row (type registered) for the org + a portal credential store (password hashed); login issues a portal Bearer token.
- Endpoints (versioned under `/api/v1`, rate-limited): register, login, logout, me, email verify (or verify-link flow), profile update, address CRUD against `customer_addresses`.
- Reuse existing `customers` / `customer_addresses` tables; add only the credential/link columns or table needed for portal auth.
- Money/DECIMAL and inventory rules unchanged (this story does not post sales).

**Ask First:**
- Multi-org customer portal tenancy (default: single org from config / subdomain / first org for Phase 7 MVP).
- Magically linking register-by-email to an existing POS CRM customer with the same email (default: **yes** if one active match in org; else create new).

**Never:**
- Granting portal customers staff roles or Admin/POS permissions.
- Implementing catalogue, cart, checkout, orders, invoices, or returns (6.2–6.5).
- Replacing staff Customer CRM Admin/POS APIs.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Register new email | Valid name/email/password | Customer + portal account; token or verify-required response | Duplicate email → 422 |
| Register existing CRM email | Active customer same email in org | Link portal account to that customer | Conflict if already linked |
| Login | Valid credentials | Bearer token + me payload | 401 invalid |
| Login staff email | Staff User only | 401 (not a portal account) | N/A |
| Me with portal token | Authenticated | Profile + addresses | 401 expired |
| Staff token on portal route | Staff Sanctum token | 401/403 | N/A |
| Address CRUD | Authenticated customer | Create/update/delete own addresses | Cannot touch others’ |

</frozen-after-approval>

## Code Map

- Staff auth today: `Modules/Authentication` LoginController + `App\Models\User` + Sanctum (`config/auth.php` web/users only)
- CRM: `Modules/Customers` Customer / CustomerAddress — no password (`Customer.php`, migration `2026_08_27_210000_…`)
- New module: `backend/Modules/CustomerPortal/` (nWidart like Sync) — controllers, services, migrations for portal credentials
- Prefer `customer_accounts` (or `customers.user`-style) Authenticatable with `HasApiTokens` + ability `portal` — do **not** put portal people on staff `users` without isolation
- Middleware: `ability:portal` (or custom) on portal routes; ensure staff routes stay staff-only
- New SPA: `frontend/customer-portal/` mirror `frontend/pos` Vite layout; pages: Login, Register, Verify, Account/Profile, Addresses
- Seed/config: org resolution for portal; throttle `auth` routes
- UX: shop mockups have Account chrome only — invent simple auth screens using DESIGN tokens (`EXPERIENCE.md` Phase 7 note)
- Continuity: epic-6-context auth isolation + CU matrix gap — define minimal portal abilities in this story

## Tasks & Acceptance

**Execution:**
- [x] CustomerPortal module + portal Authenticatable linked to `customers` + migrations
- [x] Portal auth API (register/login/logout/me/verify/profile/addresses) + rate limits + isolation tests
- [x] Scaffold `frontend/customer-portal/` SPA with auth/account/address flows
- [x] Document run instructions (README); sprint-status `6-1-…` / epic-6 in-progress
- [x] Feature + SPA unit/smoke tests for matrix

**Acceptance Criteria:**
- Given a new email, when the customer registers, then a CRM customer and portal credential exist and they can obtain a portal token (after verify if required).
- Given portal credentials, when they login, then `/portal/me` (or equivalent) returns their profile and staff Admin APIs reject that token.
- Given a staff token, when used on a portal route, then the request is unauthorized.
- Given an authenticated portal customer, when they manage addresses, then only their own `customer_addresses` change.
- Given this story ships, when browsing the portal, then catalogue/cart routes are stubbed or absent (not implemented).

## Design Notes

**Org resolution (MVP):** `config('customer_portal.organization_id')` or first organization — document in README; multi-tenant host mapping later.

**Email verify:** Prefer signed URL or code flow; block checkout later stories until verified if that gate is set — for 6.1, either issue token only after verify **or** issue token with `email_verified_at` null and expose flag on me (pick one and test it; prefer verify-required before full session if cheap).

**Route prefix:** `/api/v1/portal/auth/*` and `/api/v1/portal/account/*` to keep clear of staff `/auth` and `/customers`.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: auth isolation + register/login/address pass
- `cd frontend/customer-portal && npm run test && npm run build` -- expected: pass (or build-only if minimal test harness)
- Staff/POS suites still green: `cd backend && php artisan test --filter=Auth` (smoke)

**Manual checks:**
- Register → verify → login → see profile/addresses in portal SPA
- Portal token cannot call `/api/v1/auth/me` staff endpoint
- Staff token cannot call portal me

## Suggested Review Order

**Portal auth isolation**

- Portal account Authenticatable + register/login/verify
  [`PortalAuthService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalAuthService.php)

- Staff vs portal middleware
  [`EnsurePortalAccount.php:1`](../../backend/app/Http/Middleware/EnsurePortalAccount.php)
  [`EnsureStaffUser.php:1`](../../backend/app/Http/Middleware/EnsureStaffUser.php)

**SPA**

- Customer portal shell + auth views
  [`frontend/customer-portal/`](../../frontend/customer-portal/)

**Tests**

- Isolation + register matrix
  [`PortalAuthApiTest.php:32`](../../backend/tests/Feature/PortalAuthApiTest.php#L32)
