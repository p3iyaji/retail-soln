# PRD Addendum — Technical Depth from Proposal

This addendum preserves implementation-relevant detail from `docs/proposal.md` that does not belong in the PRD narrative. Downstream architecture and build agents should reference this alongside `ARCHITECTURE-SPINE.md`.

---

## Technology Stack (Resolved)

| Layer | Choice | Notes |
| --- | --- | --- |
| Backend | Laravel (pin at build), PHP 8.4+, MySQL 8+, Redis | Queues, Scheduler, Events, Notifications |
| Auth | Laravel Fortify + Sanctum | Policies/Gates for authorization |
| Modularity | Laravel nWidart modules | Domain-driven, not one module per table |
| Frontend | **Vue 3 SPA + TypeScript + Tailwind + Vite** | **NOT Inertia.js** per proposal line 77 |
| API | Versioned `/api/v1` REST + JSON | All clients consume API |
| Offline (Phase 6) | PWA, IndexedDB, Dexie.js | Service worker, offline shell |
| Infra | Docker, Nginx, Redis, MySQL, S3-compatible storage | VPS/cloud/K8s-ready |

---

## Domain Modules (Suggested)

```text
Authentication · Organization · Stores · Users · Personnel
Roles & Permissions · Products · Categories · Brands · Units
Pricing · Customers · Suppliers · Purchasing · Inventory
POS · Sales · Payments · Invoices · Returns · Refunds
Orders · Deliveries · Promotions · Taxes · Accounting
Notifications · Reporting · Audit · Settings
```

Group related domains; avoid excessive module count.

---

## Offline Sync Model (Phase 6)

Each offline transaction carries: UUID, device ID, store ID, terminal ID, user ID, created timestamp, local sequence, sync status, server timestamp.

Sync flow: Offline Device → Sync Queue → API → Server Validation → Transaction Processing → Inventory Update → Payment Recording → Invoice Generation → Sync Confirmation.

**Idempotency:** Unique transaction UUIDs on all sync endpoints. Never double-process on retry/reconnect.

**Conflict resolution:** Inventory reconciled via ledger on server; no silent overwrite. Server re-validates price, tax, auth, sellability. Oversell → accept + manager approval (AD-9).

**Installments:** Creating new installment plans is **online-only** by default (AD-14). Collecting an installment while offline may queue like a payment once Sync epic extends envelopes — not in MVP.

**Local cache:** Products, variants, prices, tax, customers, inventory snapshot, POS config, pending sales/payments/returns, sync metadata.

---

## Database Concepts (Key Tables)

organizations, stores, warehouses, terminals, users, employees, roles, permissions, products, product_variants, categories, brands, units, barcodes, customers, customer_addresses, customer_groups, suppliers, supplier_contacts, purchase_orders, purchase_order_items, goods_receipts, goods_receipt_items, inventory_movements, inventory_balances, stock_transfers, stock_transfer_items, stock_counts, sales, sale_items, sale_payments, orders, order_items, order_fulfilments, shipments, deliveries, invoices, invoice_items, payments, refunds, price_lists, prices, promotions, promotion_rules, taxes, cash_registers, cashier_sessions, cash_movements, notifications, audit_logs, devices, sync_batches, sync_records

**Rules:** UUIDs where appropriate · decimal for money (never float) · FK constraints · composite indexes · store-scoped indexes

---

## API Architecture

- Versioned `/api/v1`
- Sanctum token auth for all clients
- API Resources for response shaping
- Rate limiting on public endpoints
- Sync endpoints: push, pull, status (Phase 6)
- Idempotency keys on write endpoints

---

## POS Hardware (Future)

Receipt printers, barcode scanners, cash drawers, payment terminals — protocol choices deferred. Design POS UI to abstract hardware via service layer.

---

## Implementation Phases (Full Roadmap)

| Phase | Name | Scope |
| --- | --- | --- |
| 1 | Architecture | Docs only — no app code until approved |
| 2 | Foundation | Auth, org, stores, RBAC, settings, audit |
| 3 | Product & Inventory | Catalog, ledger, transfers, counts |
| 4 | Purchasing | Suppliers, PO, GRN, payments |
| 5 | POS (online) | POS UI, payments, receipts, cash |
| 6 | Offline POS | PWA, IndexedDB, sync |
| 7 | Customer Portal | Registration, catalogue, checkout |
| 8 | Fulfilment | Pick, pack, ship, pickup |
| 9 | Reporting | Dashboards, analytics |
| 10 | Hardening | Security, performance, DR, E2E |

---

## Development Rules (from Proposal §50–55)

Before each major feature: explain architecture, identify modules/tables/API/frontend/auth/sync/test impact, implement smallest coherent unit, run tests, fix failures, update docs.

Never modify unrelated functionality to make a feature work.

Financial accuracy: decimal types, atomic transactions, no async for consistency-critical ops.

Inventory accuracy: ledger-only mutations, no `$product->stock--` as sole mechanism.

Offline safety: idempotency, visible sync status, server authoritative validation.
