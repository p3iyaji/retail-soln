# Handover — Start Epic 1 (Platform Foundation)

**Date:** 2026-08-24  
**Project:** retail-soln  
**Owner:** Paul  
**Purpose:** Paste or attach this note in a **new Cursor session** to resume development at Epic 1 without re-deriving planning.

---

## One-line status

Planning is complete through **Epic 0** (approved). **No application code exists yet.** Next work: **Epic 1 — Platform Foundation**, starting with story **1.1 Laravel modular monolith scaffold**. Use `bmad-build` (or implement against `epics.md` Story 1.1 AC).

---

## What the new session should do first

1. Read `AGENTS.md` (policy + paths).
2. Read this handover.
3. Skim binding decisions below; open full docs only as needed.
4. Confirm with Paul: proceed with **Story 1.1**.
5. Implement scaffold: Laravel + nWidart modules + Docker + `/api/v1` + Sanctum-ready routing + CI stub — **Vue SPA, no Inertia**.
6. Update `_bmad-output/implementation-artifacts/sprint-status.yaml` (`epic-1` → `in-progress`, `1-1-laravel-scaffold` → `in-progress` / `done`).
7. After scaffold exists: refresh `AGENTS.md` **Running and verifying** with real commands (`bmad-project-context` refresh).

**Do not** start POS UI, inventory ledger, or customer portal in Epic 1.

---

## Artifact map (source of truth)

| Role | Path |
| --- | --- |
| Agent rules | `AGENTS.md` |
| Raw intent | `docs/proposal.md` |
| PRD | `_bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/prd.md` |
| PRD tech addendum | `.../addendum.md` |
| Architecture spine (**final**, AD-1–AD-15) | `_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md` |
| Domain / DB / API / Sync / Security / Permissions | same folder: `domain-model.md`, `database-design.md`, `api-design.md`, `offline-sync-design.md`, `security-design.md`, `permission-matrix.md` |
| Epic 0 gate | `.../epic-0-approval-gate.md` |
| Epics & stories | `_bmad-output/planning-artifacts/epics.md` |
| Sprint board | `_bmad-output/implementation-artifacts/sprint-status.yaml` |
| UX spines | `_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/DESIGN.md`, `EXPERIENCE.md` |
| HTML mockups | `.../mockups/index.html` (POS, admin, proforma, **shop Phase 7**) |
| Readiness analysis | `_bmad-output/planning-artifacts/analysis/proposal-readiness-analysis.md` |

**Conflict rule:** Spine + PRD win over `docs/proposal.md` (proposal still lists Inertia; **AD-1 forbids Inertia**).

---

## Sprint status snapshot

| Item | Status |
| --- | --- |
| Epic 0 (architecture docs) | **done** |
| Epic 1 stories 1.1–1.6 | **backlog** ← start here |
| Epics 2–4 (MVP) | backlog |
| Epics 5–9 (post-MVP) | backlog |

MVP release boundary = end of **Epic 4** (online POS). Customer portal = **Epic 6 / Phase 7**.

---

## Epic 1 stories (implement in order)

From `epics.md`:

| ID | Story | Intent |
| --- | --- | --- |
| **1.1** | Laravel modular monolith scaffold | Docker (MySQL, Redis, Nginx, app, queue), nWidart modules, `/api/v1`, Sanctum middleware group, Organization + Auth stubs, PHPUnit in CI |
| **1.2** | Auth Fortify + Sanctum | Login/logout/me, tokens, rate limit, 2FA for privileged roles |
| **1.3** | Organization & stores | Stores/warehouses CRUD, currency/tax fields |
| **1.4** | Users, roles, permissions | Seed `permission-matrix.md`; Policies; store access |
| **1.5** | Settings & audit | Append-only audit_logs |
| **1.6** | Admin portal shell | Vue 3 + TS + Tailwind + Vite SPA; Bearer token; nav stubs; **no Inertia** |

Intended layout (create in 1.1):

```text
backend/          # Laravel + Modules/
frontend/admin/   # Vue SPA (SM shares this app — role-based nav)
frontend/pos/     # later Epic 4
docker/
```

---

## Binding decisions (do not re-litigate)

| ID | Decision |
| --- | --- |
| AD-1 | Vue 3 SPA + `/api/v1` only — **no Inertia** |
| AD-2 | Inventory only via ledger service |
| AD-3 | Atomic financial transactions; DECIMAL money |
| AD-4 | Store-scoped auth (`X-Store-Id` + Policies) |
| AD-5 | Offline idempotency via client UUID (Phase 6) |
| AD-9 | Offline oversell → allow negative + manager approval |
| AD-10 | Payments: **manual + Stripe + Paystack** |
| AD-11 | Loyalty **rules engine** in v1 (Epic 4+) |
| AD-12 | Full accounting ledger (UI may trail POS MVP) |
| AD-13 | Receipts: **ESC/POS** abstraction |
| AD-14 | **Installment plans** = first-class AR (≠ split payment) |
| AD-15 | **Proforma** = non-fiscal until convert; separate number series |

**OQ resolved:** gift cards deferred; installments + proforma in MVP scope (Epic 4 stories 4.9–4.12).

**Tenancy:** single organization per deployment in v1 (not SaaS multi-tenant).

---

## Still open (non-blocking for 1.1)

- Accounting UI timing vs POS MVP (AD-12)
- Loyalty story detail under Epic 4 (AD-11) — matrix exists; expand stories if needed
- Package manager for frontend (npm vs pnpm) — decide at scaffold
- Exact Laravel version pin (proposal said 13 — verify current stable at scaffold time)

---

## Suggested first prompt for the new session

```text
Read AGENTS.md and _bmad-output/handover-epic-1.md.
We are starting Epic 1. Implement Story 1.1 (Laravel modular monolith scaffold)
per epics.md and ARCHITECTURE-SPINE.md. No Inertia. Update sprint-status.yaml
as you go. Do not implement POS or inventory yet.
```

Or invoke: **`bmad-build`** with intent = Story `1-1-laravel-scaffold`.

---

## Repo reality check

- Git: planning artifacts only; **main may have no commits / no app code**.
- BMAD Method installed under `_bmad/` and `.agents/skills/`.
- Communication language: English; user: Paul.

---

## Done when Epic 1 is “ready for Epic 2”

- Docker stack boots; migrations run for org/store/user/RBAC/settings/audit
- API auth works with Sanctum
- Permission matrix seeded and enforced in tests for store isolation
- Admin SPA logs in and shows shell nav
- `AGENTS.md` run commands filled in
- Sprint: Epic 1 stories `done` (or last story in `review`)
