---
title: 'Story 4.7: Cashier sessions and cash management'
type: 'feature'
created: '2026-08-28'
status: 'done'
baseline_commit: '1bd0d30'
---

## Summary

Cashier opens register session with opening float; cash in/out, sale cash, and void refunds tracked; close computes expected vs actual variance. Sales require an open session. POS chrome shows session status and gates Pay (F9).

## Tasks

- [x] Migrations: `terminals`, `cashier_sessions`, `cash_movements`; FKs on `sales`
- [x] `POST /pos/sessions/open`, `GET /pos/sessions/current`, `POST /pos/sessions/{id}/close`, `POST /pos/sessions/{id}/cash-movements`, `GET /pos/sessions`, `GET /pos/sessions/{id}`
- [x] `CashierSessionService`, `TerminalService`; wire `SaleCompletionService` + `SaleVoidService`
- [x] `CashierSessionApiTest` (8 tests); update sale tests for required `sessionId`
- [x] POS: `SessionPanel`, session chrome, Pay gated on open session

## Deferred

- Admin UI for session review (API list/show available for store managers)
- Refunds from returns API (4.8) — `refund_cash` movement type ready for voids today
