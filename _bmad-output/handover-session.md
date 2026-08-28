# Session handover — retail-soln (2026-08-28)

## Current state

| Item | Value |
| --- | --- |
| **Branch** | `main` (sync after commit) |
| **Epic** | **4 — POS (online)** in progress |
| **Next story** | **4.7 — Cashier sessions and cash management** |
| **Workflow** | **bmad-build**: plan → approve spec → implement → review → commit/PR |

## Epic 4 progress

| Story | Status |
| --- | --- |
| 4.1–4.6 | `done` |
| **4.7** | `backlog` — **next** |
| 4.8–4.12 | `backlog` |

## Dev quick reference

```bash
cd backend && php artisan serve          # API :8000
cd frontend && npm run dev               # Admin :5173
cd frontend && npm run dev:pos           # POS :5174
```

**Logins:** `admin@retail.local` / `password`

## Story 4.7 preview

- Cashier sessions: open/close, float, cash in/out, variance
- `POST /pos/sessions/open`, close, link sales to `sessionId`
- POS Pay gating when session open (deferred partially in 4.5)

## Next session

Say: **“Continue Story 4.7 — plan and implement”**
