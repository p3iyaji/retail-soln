---
title: 'Story 3.4: Supplier invoices and payments'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: 'e3f1440ee827705e5e4315df4fc0b291aea3cea8'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** After goods are received, there is no way to record supplier invoices and payments, so accounts payable and outstanding supplier balances are invisible.

**Approach:** Add supplier invoice and payment CRUD in Purchasing (`purchase.pay`), matching invoices to goods receipts, supporting partial payments against invoices, and exposing a computed supplier outstanding balance—all DECIMAL—with an Admin AP page. No purchase returns or full accounting journals.

## Boundaries & Constraints

**Always:**
- Gate invoice/payment mutations and list with `purchase.pay` (org-scoped).
- Invoice must reference a goods receipt that belongs to the same org/supplier; only receivable POs that have been received (`partial` or `received` GRNs exist).
- All money fields `DECIMAL(15,4)` strings in API; camelCase JSON; org via `OrganizationService::current()`.
- Payments allocate to a specific invoice; payment amount ≤ invoice remaining (total − prior payments); partial payments allowed.
- Supplier “balance” / outstanding is **computed** (Σ invoice totals − Σ payments), not a separately mutated stock-like balance table.
- Sensitive creates/updates audited; Idempotency-Key on payment (and invoice create) POSTs.
- Never mutate inventory (AD-2).

**Ask First:**
- Posting double-entry journal entries (AD-12) from invoice/payment in this story (default: skip / stub hook only).
- Allowing payments without an invoice (open AP credit).

**Never:**
- Purchase returns (Story 3.5).
- Changing GRN/ledger posting rules from 3.3.
- Float money; inventing endpoints beyond CRUD `/supplier-invoices` and `/supplier-payments` (plus optional balance read on supplier or a small summary endpoint if needed for UI).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create invoice | Valid goodsReceiptId + amount (or derived) | 201 invoice; outstanding increases | 422 if GRN missing/wrong org; 403 |
| Match rule | GRN for supplier S | Invoice supplier = GRN’s PO supplier | 422 mismatch |
| Record payment | Invoice + amount ≤ remaining | Payment stored; remaining/outstanding drop | 422 overpay |
| Partial payments | Two payments summing to total | Invoice fully paid; status reflects paid | N/A |
| Overpay | amount &gt; remaining | Rejected | 422 |
| Unauthorized | No `purchase.pay` | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |
| Balance read | Supplier with invoices/payments | Outstanding = invoices − payments (DECIMAL) | N/A |

</frozen-after-approval>

## Code Map

- `backend/Modules/Purchasing/` -- extend; `GoodsReceipt` / `GoodsReceiptItem` / `GoodsReceiptService` from 3.3
- `backend/Modules/Purchasing/app/Models/PurchaseOrder.php` -- link chain supplier ← PO ← GRN
- `backend/Modules/Purchasing/routes/api.php` -- add CRUD `/supplier-invoices`, `/supplier-payments`
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` -- `purchase.pay` (SA/OA/PO/AC)
- `backend/Modules/Purchasing/app/Http/Requests/StoreGoodsReceiptRequest.php` -- permission/org FormRequest pattern
- `frontend/admin/src/layouts/AppShell.vue` + `router/index.ts` -- add Payments / AP nav
- `frontend/admin/src/views/PurchaseOrdersView.vue` + `SuppliersView.vue` -- UI/permission patterns; show outstanding on suppliers optional
- Continuity 3.3: GRN `unit_cost` × qty for suggested invoice amount; no inventory writes
- `backend/tests/Feature/GoodsReceiptApiTest.php` -- fixture pattern for approved+received PO

## Tasks & Acceptance

**Execution:**
- [x] Migrations: `supplier_invoices`, `supplier_payments` (org, supplier, goods_receipt_id, amounts DECIMAL, status, payment invoice_id, method/notes, idempotency_key)
- [x] Models/resources/requests + `SupplierInvoiceService` / `SupplierPaymentService` (match GRN, compute remaining, update invoice paid status) + controllers/routes
- [x] Computed outstanding helper (per supplier) exposed on invoice list meta and/or `GET /suppliers/{id}` enrichment / small balance field for Admin
- [x] Admin AP page: list invoices, create from GRN/PO context, record (partial) payments; show remaining + supplier outstanding
- [x] `backend/tests/Feature/SupplierInvoicePaymentApiTest.php` — matrix (match, partial pay, overpay, 403, DECIMAL, balance)

**Acceptance Criteria:**
- Given a goods receipt, when an invoice is created matched to that GRN, then it persists with DECIMAL amounts and increases supplier outstanding.
- Given an open invoice, when a partial payment is recorded, then remaining decreases and outstanding updates; further payments can complete the invoice.
- Given payment amount above remaining, when POST runs, then 422 and no payment row.
- Given a user without `purchase.pay`, when creating invoice/payment, then 403.
- Given Admin SPA, when opening the AP/payments UI, then permitted users can invoice and pay.

## Spec Change Log

## Design Notes

**Match model:** `supplier_invoices.goods_receipt_id` required (unique optional—one invoice per GRN for MVP, or allow multiple with sum ≤ receivable value). Denormalize `supplier_id` + `purchase_order_id` from GRN→PO. Default `totalAmount` = Σ (GRN item qty × unit_cost); client may override with validation ≥ 0.

**Invoice status:** `open` → `partial` → `paid` based on Σ payments vs total (mirror PO receive semantics).

**Payments:** `supplier_payments.supplier_invoice_id` required; `amount` DECIMAL; optional `method`/`reference`/`paidAt`. Remaining = invoice.total − Σ payments.

**Outstanding balance:** `outstanding = Σ invoice.total − Σ payment.amount` for supplier (org-scoped). Do not invent `supplier_balances` table in 3.4.

**AD-12:** No journal posting required; optional empty hook/comment for later Accounting module.

**UI:** New “Payments” (or “AP”) view: pick supplier → list invoices/outstanding; create invoice selecting a GRN; pay against an invoice. Link from Purchase Orders optional.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=SupplierInvoice` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Receive a PO; create invoice from GRN; pay partially then fully; confirm supplier outstanding reaches zero.

## Suggested Review Order

**AP domain**

- Invoice create matched to GRN with DECIMAL totals
  [`SupplierInvoiceService.php:30`](../../backend/Modules/Purchasing/app/Services/SupplierInvoiceService.php#L30)

- Partial payments and invoice status open→partial→paid
  [`SupplierPaymentService.php:31`](../../backend/Modules/Purchasing/app/Services/SupplierPaymentService.php#L31)

- Computed outstanding via bcadd/bcsub (no float sums)
  [`SupplierOutstandingService.php:14`](../../backend/Modules/Purchasing/app/Services/SupplierOutstandingService.php#L14)

**API**

- supplier-invoices and supplier-payments routes
  [`api.php:37`](../../backend/Modules/Purchasing/routes/api.php#L37)

**Admin**

- Payments page: invoice from GRN, pay, outstanding
  [`PaymentsView.vue:249`](../../frontend/admin/src/views/PaymentsView.vue#L249)

**Tests**

- Match, partial pay, overpay, idempotency, audit, balance
  [`SupplierInvoicePaymentApiTest.php:109`](../../backend/tests/Feature/SupplierInvoicePaymentApiTest.php#L109)
