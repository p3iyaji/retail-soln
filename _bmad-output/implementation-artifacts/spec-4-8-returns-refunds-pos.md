---
title: 'Story 4.8: Returns and refunds (POS)'
type: 'feature'
created: '2026-08-28'
status: 'done'
baseline_commit: '96a66bb'
---

## Summary

Process full/partial returns against completed sales with reason codes, inventory `return` movements, and refunds (cash, card, store credit). Cash refunds post `refund_cash` session movements when session is open.

## Tasks

- [x] Migrations: `sale_returns`, `sale_return_items`, `refunds`
- [x] `GET /pos/sales/{sale}/returnable`, `POST /pos/returns` (`sales.refund` + Idempotency-Key)
- [x] `SaleReturnService` — prorated lines, returnable qty caps, installment plan guard (when table exists)
- [x] `SaleReturnApiTest` (6 tests)
- [x] POS `ReturnPanel` — sale lookup, line qty, reason, refund method

## Deferred

- Exchange flow (return + new sale in one txn)
- Installment recalculation rules (4.9+)
- Customer history returns section population
