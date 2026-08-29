---
title: 'Story 5.3: Offline sale queue with UUID'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'e54ef551491e2f8790ddabdca7aaf995c0d238e8'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-2-local-cache-sync.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Offline cashiers can search and price from cache (5.2) but checkout still calls the API. When the network is down, payment fails and the sale is lost — there is no local queue.

**Approach:** Persist offline completed checkouts into Dexie `pending_sales` using the offline-sync transaction envelope (client `uuid` PK + monotonic `localSequence`). Online checkout stays on `POST /pos/sales`. Do **not** push the queue to the server yet — that is Story 5.4.

## Boundaries & Constraints

**Always:**
- Offline checkout writes one envelope with `payloadType: 'sale'`, `syncStatus: 'pending'`, payload matching online `CompleteSaleInput` (same `uuid` in row and body).
- Use one client UUID as the sale identity (align online path: body `uuid` === `Idempotency-Key` when convenient).
- Monotonic `localSequence` per device via `sync_meta`; reuse `ensureDeviceUuid()`.
- Block offline **installment plan create** with a clear error (online-only).
- After successful enqueue: clear cart, show local success (“Queued — will sync when online”); do not call receipt/print APIs.
- Money remains DECIMAL strings; do not mutate inventory on the client.
- Online `PaymentPanel.submit` behaviour unchanged when online (still `completeSale`).

**Ask First:**
- Queueing offline returns / cash movements / session close (design allows; 5.3 scopes to **sale** only unless you expand).
- Changing envelope field names away from offline-sync design.

**Never:**
- `POST /api/v1/sync/push` or marking rows `synced` via server (5.4).
- Offline installment plans; inventing a different idempotency scheme.
- Silent discard of offline checkout failures.
- Auto-push on reconnect in this story (optional hook stub only if inert).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Offline pay cash/card | Cart + session + offline | Row in `pending_sales` (pending); cart cleared; success UX | Enqueue fail → error, cart kept |
| Offline + installment | Installment toggled | Submit blocked; message online-only | N/A |
| Online pay | Network up | Existing `completeSale` path | Existing API errors |
| Duplicate local uuid | Same uuid write twice | Reject / no second row (PK) | Surface error |
| Sequence | Two offline sales | `localSequence` increases by 1 each | Persist sequence in sync_meta |

</frozen-after-approval>

## Code Map

- `frontend/pos/src/components/PaymentPanel.vue` (~L180–235) -- intercept `submit`: offline enqueue vs online `completeSale`
- `frontend/pos/src/sync/saleCheckout.ts` (+ test) -- shared offline/online checkout; one uuid for body + Idempotency-Key; block offline installments / held sales
- `frontend/pos/src/api/posSales.ts` -- `CompleteSaleInput` / `completeSale` shapes to reuse in payload
- `frontend/pos/src/db/posDb.ts` -- migrate `pending_sales` from `++id` to **uuid PK** + indexes (`localSequence`, `syncStatus`, `storeId`); drop at v4, redefine at v5
- `frontend/pos/src/db/posDb.ts` `ensureDeviceUuid` / `createClientUuid` -- reuse for envelope + sale uuid
- `frontend/pos/src/stores/pos.ts` -- clear cart / session helpers after enqueue; optional pending-count later (5.6)
- `frontend/pos/src/stores/auth.ts` -- `user.id` for envelope `userId`
- New: `frontend/pos/src/sync/pendingSales.ts` -- `enqueueSale`, `nextLocalSequence`, list pending (test helpers)
- New tests: `pendingSales.test.ts`, `saleCheckout.test.ts`, `PaymentPanel.offline.test.ts`
- Continuity: 5.2 deferred note — migrate `pending_sales` PK to uuid (this story)

## Tasks & Acceptance

**Execution:**
- [x] `frontend/pos/src/db/posDb.ts` -- `pending_sales` uuid PK + envelope types; schema migration
- [x] `frontend/pos/src/sync/pendingSales.ts` (+ test) -- sequence + enqueue transactional write
- [x] `frontend/pos/src/components/PaymentPanel.vue` -- offline sale queue path; block installments offline
- [x] Align online sale uuid with Idempotency-Key (same value) for AD-5 consistency
- [x] `frontend/pos/README.md` + sprint-status `5-3-…` -- document queued offline sales
- [x] Unit-test I/O matrix (enqueue, sequence, installment block, online path untouched)

**Acceptance Criteria:**
- Given offline checkout without installments, when Pay succeeds locally, then a `pending_sales` row exists with `syncStatus=pending` and matching envelope fields, and the cart is cleared.
- Given offline checkout with installment plan enabled, when Pay is attempted, then no row is written and the cashier sees an online-only message.
- Given two consecutive offline sales, when both enqueue, then `localSequence` values are strictly increasing.
- Given online checkout, when Pay succeeds, then behaviour remains `POST /pos/sales` (no Dexie requirement for success).
- Given this story ships, when offline sales exist in the queue, then nothing has called `/sync/push` yet.

## Design Notes

Terminal: store chrome `terminalLabel` string; envelope `terminalId` may be null until device registration (5.7) — pass null or hash label into a nullable field; do not invent device table.

`payload` should be JSON-serializable sale body ready for 5.4 push without reshaping.

## Verification

**Commands:**
- `cd frontend/pos && npm run test` -- expected: all pass including pendingSales
- `cd frontend/pos && npm run build` -- expected: success

**Manual checks:**
- Offline: complete cash sale → IndexedDB `pending_sales` row; cart empty; badge OFFLINE
- Online: normal sale still prints receipt path

## Suggested Review Order

**Checkout orchestration**

- Single entry for offline enqueue vs online POST; one uuid for body + Idempotency-Key
  [`saleCheckout.ts:35`](../../frontend/pos/src/sync/saleCheckout.ts#L35)

- Blocks offline installments and held sales before any write
  [`saleCheckout.ts:36`](../../frontend/pos/src/sync/saleCheckout.ts#L36)

**Payment UI**

- Offline branch: queue success UX, clear cart, skip receipt/print
  [`PaymentPanel.vue:183`](../../frontend/pos/src/components/PaymentPanel.vue#L183)

- Local “Queued — will sync when online” success screen
  [`PaymentPanel.vue:283`](../../frontend/pos/src/components/PaymentPanel.vue#L283)

**Dexie queue**

- Transactional envelope write + monotonic localSequence (meta ∩ table max)
  [`pendingSales.ts:34`](../../frontend/pos/src/sync/pendingSales.ts#L34)

- Drop `++id` pending_sales then recreate uuid PK (v4→v5)
  [`posDb.ts:146`](../../frontend/pos/src/db/posDb.ts#L146)

**Tests / docs**

- Helper matrix + PaymentPanel wiring (cart clear / enqueue fail)
  [`saleCheckout.test.ts:18`](../../frontend/pos/src/sync/saleCheckout.test.ts#L18)
  [`PaymentPanel.offline.test.ts:1`](../../frontend/pos/src/components/PaymentPanel.offline.test.ts#L1)
  [`pendingSales.test.ts:1`](../../frontend/pos/src/sync/pendingSales.test.ts#L1)
  [`posDb.test.ts:109`](../../frontend/pos/src/db/posDb.test.ts#L109)
