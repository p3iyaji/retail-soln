---
title: 'Story 4.6: Hold, suspend, and void sales'
type: 'feature'
created: '2026-08-28'
status: 'in-review'
baseline_commit: 'e631849'
---

## Summary

Hold/suspend cart as `held` sales, list/resume from POS, void held (no stock) or completed (reverse via `MovementType::Return`).

## Tasks

- [x] `SaleHoldService`, `SaleVoidService`, `SaleLineCalculator` extract
- [x] `POST /pos/sales/hold`, `GET /pos/sales/held`, `GET /pos/sales/{id}`, `POST /pos/sales/{id}/void`
- [x] Migration: notes, voided_at, voided_by on sales
- [x] `SaleHoldVoidApiTest` (5 tests)
- [x] POS: Hold/Void buttons, Held panel, resume flow
