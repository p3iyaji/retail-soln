---
name: retail-soln
status: final
updated: 2026-08-24
sources:
  - docs/proposal.md
  - _bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/prd.md
  - _bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md
---

# EXPERIENCE.md — retail-soln

## Foundation

**Multi-surface web:** Admin SPA, Store Manager SPA, POS PWA (desktop/tablet/touch). Customer portal deferred (Phase 7). Stack: Vue 3 + TypeScript + Tailwind + Vite; API-only (no Inertia). Visual identity: `DESIGN.md`. Form-factor default for POS: **landscape tablet / desktop**; touch + keyboard + barcode scanner.

`[ASSUMPTION]` Admin and Store Manager share one Vue app with role-based nav; POS is a separate app/route entry for performance and PWA isolation.

## Information Architecture

### Admin / Store Manager

| Surface | Reached from | Purpose |
| --- | --- | --- |
| Login | App root | Auth + optional 2FA |
| Dashboard | Nav | Role-scoped KPIs (today sales, low stock, pending approvals) |
| Stores | Nav | Store/warehouse CRUD (OA) |
| Users & roles | Nav | RBAC + store access |
| Products | Nav | Catalog, variants, import |
| Inventory | Nav | Balances, adjustments, transfers, counts, oversell exceptions |
| Purchasing | Nav | Suppliers, POs, GRN, payments |
| Customers | Nav | Profiles, history |
| Pricing | Nav | Price lists, promotions, tax, loyalty rules |
| Sales history | Nav | Search sales, receipts, voids, tax invoices |
| Proformas | Nav | Create/issue/convert proforma invoices (quotes) |
| Registers | Nav | Session review, variance (SM) |
| Settings / Audit | Nav | Org settings, audit log |
| Devices | Nav | POS device register/disable |

Store switcher in top bar when user has multiple stores (`X-Store-Id`).

→ Composition reference: `mockups/index.html` (gallery), `mockups/pos-sale.html`, `mockups/pos-payment-installments.html`, `mockups/pos-offline.html`, `mockups/admin-dashboard.html`, `mockups/proforma-document.html`. Customer portal (Phase 7): `mockups/shop-catalogue.html`, `shop-product.html`, `shop-cart.html`, `shop-checkout.html`, `shop-order.html`. Spine wins on conflict.

### POS

| Zone | Content |
| --- | --- |
| Top chrome | Store name, terminal, cashier, **sync badge**, session status |
| Left / main | Product search + scan focus, category chips, results grid |
| Right rail | Cart lines, customer chip, discounts, **total**, Pay / Hold / Void |
| Modal stack | Payment, customer lookup, price override, suspend list — max 1 deep |

## Voice and Tone

| Do | Don't |
| --- | --- |
| "Offline — sales will sync when connected" | "Oops! No internet 😅" |
| "Payment recorded" | "Success!!!" |
| "Needs manager approval — stock went negative" | "Inventory error code 4521" |
| "Session closed · variance ₦200.00" | "You have successfully completed EOD" |

Short verbs. Money always with currency symbol from store config.

## Component Patterns

| Component | Use | Behavioral rules |
| --- | --- | --- |
| Sync badge | POS chrome | Always visible; click opens last error + Retry |
| Product search | POS | Autofocus; Enter adds first hit; scanner fills same field |
| Qty stepper | Cart | +/- and keypad; never below 1 without remove |
| Pay CTA | Cart rail | Disabled until session open + ≥1 line; opens payment sheet (tenders + optional installment schedule for registered customers) |
| Store switcher | Admin top bar | Changes scope for all list queries |
| Oversell exception row | Inventory | Approve / Resolve actions; links to sale |
| Installment schedule editor | Payment sheet / Admin | Equal-split or custom amounts; shows outstanding after deposit; blocks walk-in |
| Installment collection | POS / Customers | Lookup plan by customer or sale; pay next/overdue line; sync badge rules unchanged |
| Proforma document | Admin / Proformas | Banner “PROFORMA — Not a tax invoice”; Issue / Download PDF / Convert actions; no Pay CTA |

## State Patterns

| State | Surface | Treatment |
| --- | --- | --- |
| ONLINE | POS | `{colors.online}` badge |
| OFFLINE | POS | `{colors.offline}` badge; banner once per transition |
| SYNCING | POS | `{colors.syncing}` pulse |
| SYNC ERROR | POS | `{colors.sync-error}` + Retry; do not auto-dismiss |
| Session closed | POS | Block sales; prompt Open session |
| Empty cart | POS | Quiet hint: "Scan or search to start" |
| Permission denied | Admin | Hide nav item; deep link → 403 page with back |
| Low stock | Dashboard | Ember accent list, not red unless zero/negative |
| Oversell pending | SM Inventory | Warning list until approved |

## Interaction Primitives

**POS keyboard (from PRD):**

| Key | Action |
| --- | --- |
| F2 | Focus search |
| F4 | Customer lookup |
| F8 | Discount |
| F9 | Payment |
| Esc | Cancel / close top modal |
| Enter | Confirm focused primary |

**Banned on POS:** infinite scroll in cart; hover-only actions on touch; nested modals; decorative motion that delays input.

**Motion (intentional, minimal):** sync badge pulse while SYNCING; pay button press feedback; cart line add flash 150ms. No page-load parallax.

## Accessibility Floor

- WCAG 2.2 AA on Admin and POS
- Visible focus rings on all controls (`ring` ≥ 3:1 on background)
- POS shortcuts documented in Help overlay; all actions also clickable
- Sync status not color-only — include text label
- Touch targets ≥ 44px on POS pay / qty / search clear
- Screen reader: announce sync state changes via `aria-live="polite"` (assertive for SYNC ERROR)

## Responsive & Platform

| Breakpoint | Admin | POS |
| --- | --- | --- |
| ≥1280px | Sidebar + content | 3-zone POS |
| 768–1279px | Collapsible sidebar | 3-zone compressed; cart ≥320px |
| &lt;768px | Admin usable stacked | POS **not primary**; show “Use tablet/desktop” unless forced layout |

Barcode scanner = keyboard wedge into search. ESC/POS print via print service after sale (AD-13).

## Key Flows

### UJ-1 — Amara: fast in-store sale

1. Session open with float → chrome shows session active  
2. Scan items → cart updates; tax lines visible  
3. F4 customer optional → F8 discount if permitted  
4. F9 → payment sheet (cash / card / Stripe / Paystack / split)  
5. **Climax:** total clears; receipt options; drawer kick if cash  
6. Ready for next scan (search refocused)

### UJ-2 — Amara: offline sale (Phase 6)

1. Network drop → OFFLINE badge + one banner  
2. Sale completes locally  
3. Reconnect → SYNCING → ONLINE or SYNC ERROR  
4. If oversell exception → non-blocking notice with sale ref

### UJ-3 — David: EOD reconcile

1. Registers → today’s sessions for Store A only  
2. Open session → expected vs actual → record variance  
3. **Climax:** variance saved; audit trail written

### UJ-4 — Fatima: PO to partial GRN

1. Create PO → submit → SM/OA approve  
2. Receive partial qty → balances update  
3. **Climax:** PO shows partial; remaining open

### UJ-7 — Amara: installment sale and collection

1. Select registered customer → cart total ₦300,000  
2. Payment sheet: deposit ₦100,000 cash + 2× ₦100,000 schedule  
3. **Climax:** sale completes; plan shows outstanding ₦200,000; receipt notes installment terms  
4. Later: collect installment #1 via Paystack → outstanding ₦100,000; receipt for installment

### UJ-8 — David: proforma then convert

1. Proformas → New → corporate customer + line items → Save draft  
2. Issue → download/email PDF with PROFORMA watermark  
3. Customer confirms → Convert to sale (optional installment)  
4. **Climax:** sale completes; proforma shows converted + links; stock/AR only now

## Inspiration & Anti-patterns

**Inspired by:** Square/Toast POS density; Shopify admin table clarity — not visual clones.  
**Anti-patterns:** dashboard-first POS; purple SaaS chrome; hiding offline state; card grids for cart lines.

## Open UX items (non-blocking)

- `[NOTE FOR UX]` Exact loyalty redeem UI during pay — design with Epic 4 loyalty stories  
- `[NOTE FOR UX]` Customer portal visual identity deferred to Phase 7 — **HTML mockups now exist** under `mockups/shop-*.html` (same DESIGN tokens; refine before Epic 6 build)  
- `[ASSUMPTION]` Shared admin+SM app vs two repos — prefer one app
