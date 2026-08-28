# Session handover — retail-soln (2026-08-28)

Use with `AGENTS.md`, `_bmad-output/implementation-artifacts/epic-4-context.md`.

## Current state

| Item | Value |
| --- | --- |
| **Branch** | `main` @ `b2bd7c2` (synced with `origin/main`) |
| **Epic** | **4 — POS (online)** in progress |
| **Next story** | **4.6 — Hold / suspend / void** |
| **Workflow** | **bmad-build**: plan → **[A] Approve** spec → implement → review → commit/PR/merge |

## What landed

### PR #21 (merged → `b2bd7c2`) — Story 4.5
- **Sales** module: `POST /api/v1/pos/sales` (idempotent atomic txn), receipt GET/print, `SendSaleReceipt` job
- Inventory via `MovementType::Sale`; invoice stub; `AuditableObserver`
- POS **PaymentPanel** (cash/card/split), idempotency + client UUID, walk-in default
- Spec: `_bmad-output/implementation-artifacts/spec-4-5-sale-atomic-transaction.md` (`done`)
- Tests: `SaleApiTest` (11); full suite 200 green

## Epic 4 progress

| Story | Status | Notes |
| --- | --- | --- |
| 4.1–4.5 | `done` | Pricing, customers, POS shell, cart/search, atomic sale |
| **4.6** | `backlog` | **Next:** hold/suspend/void |
| 4.7–4.12 | `backlog` | Sessions, returns, installments, proforma |

## Story 4.6 — preview (from epics / api-design)

- `POST /pos/sales/hold`, `GET /pos/sales/held`, `POST /pos/sales/{id}/void`
- Permissions: `sales.create` (hold), `sales.void` (void)
- Builds on Sales module + POS cart from 4.4/4.5

## Dev quick reference

```bash
cd backend && php artisan test
cd backend && php artisan serve --port=8001
cd frontend/pos && npm run dev   # :5174
cd frontend/admin && npm run dev # :5173
```

**Logins:** `admin@retail.local` / `password`

## Next session

1. Run **bmad-build** for Story 4.6 (or say: “Continue Story 4.6 — plan and implement”).
2. Read `epics.md` story 4.6 + `api-design.md` hold/void routes.
