# Session handover — retail-soln (2026-08-28)

## Current state

| Item | Value |
| --- | --- |
| **Branch** | `feat/4-7-cashier-sessions` (uncommitted) |
| **Epic** | **4 — POS (online)** in progress |
| **Next story** | **4.8 — Returns and refunds (POS)** |
| **Workflow** | **bmad-build**: plan → approve spec → implement → review → commit/PR |

## Epic 4 progress

| Story | Status |
| --- | --- |
| 4.1–4.7 | `done` |
| **4.8** | `backlog` — **next** |
| 4.9–4.12 | `backlog` |

## Dev quick reference

```bash
cd backend && php artisan serve          # API :8000
cd frontend && npm run dev               # Admin :5173
cd frontend && npm run dev:pos           # POS :5174
```

**Logins:** `admin@retail.local` / `password`

## Story 4.7 delivered

- `terminals`, `cashier_sessions`, `cash_movements` tables + session APIs
- Sales require open `sessionId`; cash payments recorded as `sale_cash` movements
- Void reverses cash via `refund_cash` movement
- POS: Session panel (open/close, cash in/out), chrome status, Pay/F9 gated

**Tests:** 215 passing (`CashierSessionApiTest` + updated sale tests)

## Next session

Say: **“Continue Story 4.8 — returns and refunds”**
