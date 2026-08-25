# Permission Matrix — retail-soln

**Status:** Epic 0.4 complete  
**Date:** 2026-08-24  
**Legend:** ● allow · ○ deny · S store-scoped (only assigned stores)

## Roles

| Code | Role |
| --- | --- |
| SA | Super Admin |
| OA | Organization Admin |
| SM | Store Manager |
| CA | Cashier |
| IM | Inventory Manager |
| PO | Procurement Officer |
| AC | Accountant |
| DS | Delivery Staff |
| CU | Customer (portal — Phase 7) |

## Matrix

| Permission | SA | OA | SM | CA | IM | PO | AC | DS | Scope |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `org.view` | ● | ● | ● | ○ | ● | ● | ● | ○ | org |
| `org.update` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `stores.view` | ● | ● | ● | ● | ● | ● | ● | ● | S |
| `stores.create` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `stores.update` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `users.view` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | org/S |
| `users.manage` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `users.assign-roles` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `users.assign-stores` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `roles.view` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `products.view` | ● | ● | ● | ● | ● | ● | ○ | ○ | org |
| `products.manage` | ● | ● | ○ | ○ | ● | ○ | ○ | ○ | org |
| `products.import` | ● | ● | ○ | ○ | ● | ○ | ○ | ○ | org |
| `inventory.view` | ● | ● | ● | ● | ● | ● | ○ | ○ | S |
| `inventory.adjust` | ● | ● | ● | ○ | ● | ○ | ○ | ○ | S |
| `inventory.transfer` | ● | ● | ● | ○ | ● | ○ | ○ | ○ | S |
| `inventory.count` | ● | ● | ● | ○ | ● | ○ | ○ | ○ | S |
| `inventory.approve-oversell` | ● | ● | ● | ○ | ● | ○ | ○ | ○ | S |
| `suppliers.view` | ● | ● | ● | ○ | ● | ● | ● | ○ | org |
| `suppliers.manage` | ● | ● | ○ | ○ | ○ | ● | ○ | ○ | org |
| `purchase.create` | ● | ● | ● | ○ | ○ | ● | ○ | ○ | S/org |
| `purchase.approve` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | org |
| `purchase.receive` | ● | ● | ● | ○ | ● | ● | ○ | ○ | S |
| `purchase.pay` | ● | ● | ○ | ○ | ○ | ● | ● | ○ | org |
| `pricing.manage` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `promotions.manage` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `tax.manage` | ● | ● | ○ | ○ | ○ | ○ | ● | ○ | org |
| `loyalty.manage` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `customers.view` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `customers.manage` | ● | ● | ● | ● | ○ | ○ | ○ | ○ | S |
| `pos.session` | ● | ● | ● | ● | ○ | ○ | ○ | ○ | S |
| `sales.create` | ● | ● | ● | ● | ○ | ○ | ○ | ○ | S |
| `sales.view` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `sales.void` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `sales.refund` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `sales.price-override` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `sales.discount` | ● | ● | ● | ● | ○ | ○ | ○ | ○ | S |
| `sales.installments.create` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `sales.installments.collect` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `sales.installments.view` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `sales.installments.cancel` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `sales.installments.partial` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.view` | ● | ● | ● | ○ | ○ | ○ | ● | ○ | S |
| `invoices.manage` | ● | ● | ● | ○ | ○ | ○ | ● | ○ | S |
| `invoices.proforma.view` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `invoices.proforma.create` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.proforma.update` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.proforma.issue` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.proforma.convert` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.proforma.cancel` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `invoices.proforma.honor-prices` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `payments.manual` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `payments.stripe` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `payments.paystack` | ● | ● | ● | ● | ○ | ○ | ● | ○ | S |
| `registers.reconcile` | ● | ● | ● | ○ | ○ | ○ | ● | ○ | S |
| `audit.view` | ● | ● | ● | ○ | ○ | ○ | ● | ○ | org/S |
| `settings.manage` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | org |
| `accounting.view` | ● | ● | ○ | ○ | ○ | ○ | ● | ○ | org |
| `accounting.post` | ● | ● | ○ | ○ | ○ | ○ | ● | ○ | org |
| `devices.manage` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | S |
| `reports.view` | ● | ● | ● | ○ | ● | ● | ● | ○ | S |

## Cashier notes

- Cashiers **cannot** void/refund/price-override unless SM elevates role
- `sales.discount` may be capped by store setting (amount/%)
- Cashiers **cannot** create installment plans by default (`sales.installments.create` = SM+); they **can** collect due installments
- Walk-in customers cannot receive installment plans — registered customer required
- Proforma create/convert is SM+ by default; cashiers may **view** proformas
- Proforma ≠ tax-invoice `draft` — converting is required before inventory/AR effects

## Seeding

Seed all permission strings in Epic 1 Story 1.4; attach default role maps from this matrix. Tests must cover at least: SM cannot read other store; CA cannot refund; PO cannot approve own PO if dual-control setting on.
