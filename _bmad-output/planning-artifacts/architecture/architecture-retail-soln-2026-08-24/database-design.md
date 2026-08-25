# Database Design — retail-soln

**Status:** Epic 0.1 companion (table proposal)  
**Date:** 2026-08-24  
**Rules:** DECIMAL for money · UUID for offline/client entities · FKs + unique + store indexes · never float money

## Conventions

| Topic | Rule |
| --- | --- |
| Primary keys | `bigint unsigned` auto-increment for server entities |
| Client IDs | `uuid` CHAR(36) unique where offline/idempotent |
| Money | `DECIMAL(15,4)` |
| Qty | `DECIMAL(15,4)` |
| Timestamps | `created_at`/`updated_at` UTC; soft deletes only where business requires |
| Tenancy | `organization_id` on all tenant tables |
| Store scope | `store_id` nullable only for org-level entities |

## MVP Tables (Phases 2–5)

### Foundation
- `organizations`
- `stores` (currency, tax_profile_id, settings JSON)
- `warehouses`
- `users`, `employees`
- `roles`, `permissions`, `role_permission`, `model_has_roles` (or custom RBAC tables)
- `store_user` (user_id, store_id, is_default)
- `settings` (org/store scoped key-value)
- `audit_logs` (append-only; no UPDATE/DELETE grants in app)

### Catalog
- `categories`, `brands`, `units`
- `products`, `product_variants`
- `barcodes` (unique per organization)
- `product_images`

### Inventory
- `inventory_balances` UNIQUE(organization_id, location_type, location_id, product_variant_id)
- `inventory_movements` (type enum, qty signed, unit_cost, reference_type, reference_id, uuid optional)
- `stock_transfers`, `stock_transfer_items`
- `stock_counts`, `stock_count_items`
- Indexes: `(store_id, product_variant_id)`, `(reference_type, reference_id)`, `(created_at)`

### Purchasing
- `suppliers`, `supplier_contacts`, `supplier_products`
- `purchase_orders`, `purchase_order_items`
- `goods_receipts`, `goods_receipt_items`
- `supplier_invoices`, `supplier_payments`
- `purchase_returns`, `purchase_return_items`

### Pricing / Tax / Loyalty
- `price_lists`, `prices`
- `promotions`, `promotion_rules`
- `tax_rates`, `tax_categories`, `product_taxes`
- `loyalty_programs`, `loyalty_rules`, `loyalty_accounts`, `loyalty_ledger_entries`

### Sales / Cash / Returns
- `terminals` (uuid, store_id, name, status)
- `cash_registers`, `cashier_sessions`, `cash_movements`
- `customers`, `customer_addresses`, `customer_groups`
- `sales` (uuid unique), `sale_items`, `sale_payments`
- `installment_plans` (sale_id/invoice_id, customer_id, store_id, principal, fee_total, status, mode: pay_over_time|layaway)
- `installments` (plan_id, sequence, amount, due_at, status, paid_at, payment_id nullable)
- Indexes: `(customer_id, status)`, `(due_at, status)`, unique `(plan_id, sequence)`
- `invoices`, `invoice_items` (tax invoices; status includes draft|issued|…)
- `proforma_invoices` (number unique per org, status, customer_id, store_id, valid_until, converted_sale_id nullable, converted_invoice_id nullable)
- `proforma_invoice_items`
- Indexes: `(store_id, status)`, `(customer_id)`, unique `(organization_id, number)`
- `returns`, `return_items`, `refunds`

### Payments
- `payments` (provider: manual|stripe|paystack, external_id, status, idempotency_key unique)
- `payment_webhook_events` (idempotency_key unique)

### Accounting (schema early; UI may lag)
- `chart_of_accounts`
- `journal_entries`, `journal_lines` (CHECK debit/credit balance at app layer)

### Sync / Devices (Phase 6 — create tables when Epic 5 starts; design now)
- `devices`
- `sync_batches`, `sync_records`
- `inventory_oversell_exceptions` (sale_id, variant_id, qty_deficit, status pending|approved|resolved, approved_by)

## Explicit non-goals in v1 schema
- Gift card tables (OQ-1 deferred)
- Full shipment carrier tables until Epic 7

## Migration rules (from proposal)
- One concern per migration
- Never edit committed migrations in shared branches — add new ones
- Seed roles/permissions in dedicated seeders
