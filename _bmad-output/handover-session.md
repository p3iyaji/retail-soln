# Session handover — retail-soln (2026-08-29)

## Current state

| Item | Value |
| --- | --- |
| **Branch** | `feat/4-8-returns-refunds` — **large uncommitted delta** (stories 4.9–4.12 + audit polish + docs) |
| **MVP** | **Epics 1–4 complete** (online POS) |
| **Next epic** | **Epic 5 — Offline POS & Sync** |
| **Next story** | **5.1 — PWA offline shell with IndexedDB** |
| **Workflow** | Prefer **bmad-build**: plan → approve spec → implement → review → commit/PR |
| **Operator docs** | [`docs/operational-manual.md`](../docs/operational-manual.md) — end-to-end ops guide |

Sprint tracking: `_bmad-output/implementation-artifacts/sprint-status.yaml`  
(`epic-4: done`; `epic-5` + stories `5-1`…`5-7`: `backlog`)

---

## What was delivered since last handover (4.8)

### Stories 4.9–4.12 (done in working tree; not yet committed/PR’d as a unit)

| Story | Summary |
| --- | --- |
| **4.9** | Installment plan at POS checkout (deposit + schedule; registered customer; credit limit) |
| **4.10** | Collect installment payments (POS panel + API; cash session) |
| **4.11** | Proforma create/issue/PDF/cancel (Admin UI + Sales module) |
| **4.12** | Convert proforma → sale / tax invoice / both (`honorPrices` opt-in) |

### Post–Epic 4 audit / polish (also uncommitted)

- Store-scope hardening: receipts, held sale show, pricing resolve (`StoreScopeSecurityApiTest`)
- Customer history service + Admin history UI
- Installment plan cancel API
- Admin Audit Logs view; Proforma Invoices view
- POS: customer attach, installment collect, receipt print after checkout, reprice on customer change
- **Rename:** product `base_price` / `basePrice` → **`selling_price` / `sellingPrice`** (migration `2026_08_29_120000_…`; CSV still accepts legacy `basePrice`)
- MySQL fix: short index name on `purchase_return_items` (`pri_org_gr_item_idx`) — auto name exceeded 64 chars
- Operational manual expanded: pricing/cost/discounts, products & variants

### Last **committed** tip on branch

`daa27f8` — feat: add POS returns and refunds (story 4.8)

Everything after 4.8 (installments, proformas, rename, audit fixes, manual) is still **local uncommitted / untracked**.

---

## First actions next session

1. **Decide commit strategy** for the uncommitted MVP completion work:
   - Option A: one PR “complete Epic 4 (4.9–4.12) + audit polish”
   - Option B: split PRs (installments / proformas / catalog rename+security / docs)
2. **Migrate + seed** any fresh MySQL/SQLite env; run product + sales feature tests.
3. **Start Epic 5** with `bmad-build` / Story **5.1** — do **not** invent offline behaviour without the sync design.

---

## Next: Epic 5 — Offline POS & Sync

**Goal:** cashiers sell when API is unreachable; queue sales with client UUIDs; push/pull sync when online.  
**Design source of truth:**  
`_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md`  
(also Epic 0.3; AD-5 idempotency; AD-9 oversell with manager approval)

| Story | Intent |
| --- | --- |
| **5.1** | PWA offline shell + IndexedDB |
| **5.2** | Local product/inventory cache sync |
| **5.3** | Offline sale queue (UUID) |
| **5.4** | Push sync API + idempotency |
| **5.5** | Pull sync with cursors |
| **5.6** | Sync status UI + error recovery |
| **5.7** | Device registration / management |

**Constraints to respect from day one**

- Installment **plan create** stays **online-only** (Epic 4 context).
- Inventory quantity **only** via `InventoryLedgerService` (AD-2).
- Money: **DECIMAL** only; no floats.
- POS sync badge already shows ONLINE — Epic 5 must extend states (offline / syncing / error).
- Store scope: `X-Store-Id` + Policies (AD-4).

**Suggested kickoff phrase:**  
“Continue with Epic 5 / Story 5.1 — PWA offline shell with IndexedDB”

---

## Architecture & domain reminders

| Topic | Rule |
| --- | --- |
| **Product vs variant** | Product = catalog parent; **variant** = sellable SKU. Stock, sales, POs, price lists → `product_variant_id`. Creating a product auto-provisions a **Default** variant. |
| **Selling price** | Product field `sellingPrice` / `selling_price` seeds default variant `price`. POS resolves: store list → org default list → variant price. |
| **Cost** | Catalog `costPrice` + PO `unitCost` — separate; receipt does not auto-update catalog cost. |
| **Discounts** | Promotions (Admin → Pricing); POS **price override** (`sales.price-override`); proforma line discount. **F8** discount panel still a stub. |
| **Quantities** | Per **variant × location** in `inventory_balances` (`available` / reserved / damaged / in_transit). Mutations only via ledger movements. |
| **Clients** | Admin `:5173`, POS `:5174`, API `/api/v1` — **no Inertia**. |

---

## Dev quick reference

```bash
# API (match frontend .env VITE_PROXY_TARGET)
cd backend && php artisan migrate && php artisan db:seed
cd backend && php artisan serve --port=8000   # or 8001 if busy

# Frontends
cd frontend/admin && npm run dev             # :5173
cd frontend/pos && npm run dev               # :5174
# or from frontend/: npm run dev / npm run dev:pos
```

| Env | Typical value |
| --- | --- |
| Admin/POS `VITE_PROXY_TARGET` | `http://localhost:8000` (or `:8001` / Docker `:8080`) |
| Docker stack | `docker compose up -d --build` → nginx `http://localhost:8080` |

**Dev logins** (after seed):

| Email | Password | Role focus |
| --- | --- | --- |
| `admin@retail.local` | `password` | Org admin |
| `inventory@retail.local` | `password` | Catalog / stock |
| `procurement@retail.local` | `password` | Suppliers / POs |

Smoke: `curl -sf http://localhost:8000/api/v1/health`

**Tests:** `cd backend && php artisan test` (sqlite memory via `phpunit.xml`)

---

## Known gaps / do not assume done

| Area | Status |
| --- | --- |
| Stripe / Paystack live capture | Manual tender only |
| F8 line discount UI | Stub — use promotions or price override |
| Void **completed** sale from POS UI | Use returns; API void exists |
| Warehouse admin UI / session reconcile UI | API-leaning |
| Loyalty rules engine | Deferred (AD-11) |
| ESC/POS hardware driver | Print job queued; adapter pending |
| Gift cards | Deferred (OQ-1) |
| Offline sync | **Epic 5** — not started |

---

## Key paths for the next agent

| Area | Path |
| --- | --- |
| Sprint status | `_bmad-output/implementation-artifacts/sprint-status.yaml` |
| Epics / stories | `_bmad-output/planning-artifacts/epics.md` |
| Architecture spine | `_bmad-output/planning-artifacts/architecture/.../ARCHITECTURE-SPINE.md` |
| Offline design | `.../offline-sync-design.md` |
| Epic 4 context | `_bmad-output/implementation-artifacts/epic-4-context.md` |
| Ops manual | `docs/operational-manual.md` |
| Agent policy | `AGENTS.md` |
| Sales / POS API | `backend/Modules/Sales/` |
| POS SPA | `frontend/pos/` |
| Admin SPA | `frontend/admin/` |
| Inventory ledger | `backend/Modules/Inventory/app/Services/InventoryLedgerService.php` |

---

## Suggested next-session prompts

1. **“Commit and open a PR for Epic 4 completion (4.9–4.12 + polish).”**
2. **“Continue Story 5.1 — PWA offline shell with IndexedDB”** (after commit/PR if desired).
3. **“Run Epic 4 retrospective / mark epic-4-retrospective done.”** (optional)

---

*Previous session handovers: `_bmad-output/handover-epic-1.md` (Epic 1 start), prior `handover-session.md` (stopped at 4.9 backlog).*
