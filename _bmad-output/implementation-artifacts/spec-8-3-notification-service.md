---
title: 'Story 8.3: Notification service (email/SMS/in-app)'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '9e683cb197bfef9e38cd280992ea1f07feb9ac8c'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-8-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Status changes (low stock, portal pay/fulfilment) have no shared outbound notification path — only log-stub receipt/proforma jobs — so Epic 8’s multi-channel, AD-7-queued messaging does not exist.

**Approach:** Add a Notifications module with a `notifications` table, channel adapters (email + in-app real enough to persist/queue; SMS interface stub), a queued after-commit dispatcher, wire 3 high-value triggers, and a thin Admin in-app inbox. Completes Epic 8.

## Boundaries & Constraints

**Always:**
- New `Modules/Notifications` (nWidart); enable in `modules_statuses.json`.
- Persist org-scoped notification rows (type, channel, recipient ref, subject/body or payload JSON, status pending|sent|failed, related optional morph/ids, timestamps). Money never involved; DECIMAL N/A.
- Dispatcher: create records + `ShouldQueue` job(s); prefer **after commit** (`afterCommit()` / dispatch after transaction). Critical paths (checkout, pack/ship/ready, low-stock command) must not wait on delivery.
- Channels: **email** adapter (Laravel Mail or log mailer in tests — must be swappable); **in-app** writes/readable rows for staff; **SMS** interface + no-op/log stub (no live provider).
- Wire triggers (thin service calls OK — full event bus optional):
  1. Low-stock from `inventory:check-low-stock` (staff in-app ± email to store managers with `inventory.view` / configurable).
  2. Portal order paid/confirmed (`PortalCheckoutService` payment success).
  3. Portal ready-for-pickup **or** shipped (`PortalFulfilmentService`) — customer email/in-app to portal account email if present.
- Staff API: `GET /api/v1/notifications` (paginated, own/org-scoped inbox) + `PATCH …/{id}/read` (or mark-read). Permission: add `notifications.view` (SA/OA/SM/IM at minimum; seed matrix). Sanctum staff only for Admin inbox.
- Admin: nav **Notifications** gated by `notifications.view`; simple list + mark read. No preference UI in MVP.
- Feature tests: dispatch after commit (or sync queue); trigger wires create rows; email adapter invoked (Mail::fake); SMS stub not required to send; auth isolation; portal token cannot list staff inbox.

**Ask First:**
- Live SMS / push providers (default: **stub SMS interface only**).
- Customer preference / opt-out UI (default: **defer**).
- Move Sale receipt / proforma jobs into Notifications module (default: **leave Sales stubs**; note for later).
- Full domain-event bus vs direct `NotificationService::notify` calls (default: **direct service** + queue job).
- Org settings keys for enable/disable per trigger (default: **minimal** — always-on for the 3 wires; settings keys optional thin).

**Never:**
- Blocking HTTP/mail on POS/portal critical path.
- Inventing live Twilio/etc credentials in repo.
- WebSocket push.
- Treating notifications as accounting or mutating inventory/sales.
- Customer portal Admin APIs.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Paid portal order | Payment success | Queued notification(s) for customer (email and/or in-app record) | Job failure → status failed; order still paid |
| Ready/ship | Staff ready or ship | Queued customer status notification | Same |
| Low-stock command | Alerts exist | Staff in-app (± email) notifications created | No alerts → no spam |
| List inbox | Staff + notifications.view | Paginated unread/read rows | 403 without perm |
| Mark read | Own/org notification | read_at set | 404 other org |
| SMS channel requested | Adapter call | Stub logs/no-op; no external HTTP | N/A |

</frozen-after-approval>

## Code Map

- New: `backend/Modules/Notifications/` — models, migration, `NotificationService`, channel adapters, `DeliverNotificationJob`, routes
- Wire: `CheckLowStockCommand`; `PortalCheckoutService` mark paid; `PortalFulfilmentService` ready/ship
- Reuse: queue worker (Compose `queue`); Mail fake in tests; PermissionMatrix + seeder
- Pattern: Sales `SendSaleReceipt` ShouldQueue (do not migrate unless asked)
- Admin: `AppShell` + `NotificationsView.vue` + router
- Continuity: Epic 8; on ship mark `8-3` and **`epic-8: done`**

## Tasks & Acceptance

**Execution:**
- [x] Scaffold Notifications module + migration + dispatcher/adapters (email, in-app, SMS stub)
- [x] Wire low-stock, portal paid, ready-or-ship triggers (after commit)
- [x] Staff list/mark-read API + `notifications.view` RBAC
- [x] Admin Notifications inbox UI
- [x] Feature tests for matrix; ops/README; sprint 8.3 + epic-8 done on ship

**Acceptance Criteria:**
- Given a portal order becomes paid, when the transaction commits, then a queued notification job runs and an email and/or in-app record exists without failing the pay API.
- Given staff mark an order ready for pickup or shipped, when committed, then the customer notification is queued.
- Given low-stock alerts on schedule run, when the command finishes, then staff notification records exist for the store.
- Given a user without `notifications.view`, when they list notifications, then they receive 403.

## Spec Change Log

- Implemented Notifications module, queued after-commit delivery, three triggers, staff inbox + Admin UI; SMS stub; Sales receipt jobs left in place.

- 2026-08-29 — Implemented as specified; all Ask First items took their stated defaults (SMS stub only, no preference UI, Sales `SendSaleReceipt` left in place, direct `NotificationService::notify` calls rather than an event bus, minimal config keys). Deferred follow-ups recorded in `deferred-work.md`.
- 2026-08-29 — Added one config key beyond "minimal": `notifications.low_stock.cooldown_minutes` (default 720). `inventory:check-low-stock` runs hourly, so without a suppression window a persistent shortfall would notify every hour — the matrix row "No alerts → no spam" is only meaningful with it.
- 2026-08-29 — `notifications.view` seeded for SA/OA/SM/IM (the stated minimum), org-scoped rather than store-scoped because the inbox is per-user, not per-store.

## Design Notes

Keep the public surface small: one `NotificationService::notify(...)` used by triggers. Channel selection can be type→channels map in config. Prefer `Bus::dispatchAfterResponse` or job `afterCommit()` so nested DB transactions in fulfilment/checkout stay safe.

### As built

- **Ordering.** Triggers call `notify(...)` *after* their `DB::transaction` closure returns, and `DeliverNotification` additionally sets `afterCommit()`. Belt and braces: the row insert is outside the business transaction, and even if a caller wraps the whole thing in an outer transaction the job still waits for commit (Laravel's sync queue honours `afterCommit`, verified by `test_delivery_is_deferred_until_the_transaction_commits` and the rollback test).
- **Failure containment.** `DeliverNotification` catches `Throwable`, marks the row `failed`, and returns instead of rethrowing. Rethrowing would surface transport errors to the checkout / fulfilment caller under a sync queue (which is what `phpunit.xml` uses), violating "order still paid". The cost is that nothing retries a failed row — logged as deferred work.
- **Row shape.** One row per (recipient, channel). A channel whose adapter reports `supports() === false` (for example email for a recipient with no address) is a silent skip, not a `failed` row.
- **Trigger idempotency** rides on the existing guards: `markPaymentStatus` only notifies on a real transition, and ready/ship only notify when the service reports `created === true`, so idempotent replays do not re-notify.
- **Staff inbox scope** is the signed-in user's own `in_app` rows inside the current organization. Another user's row — same org or not — is a 404, which satisfies both "own/org-scoped" and the matrix's "404 other org".
- **Low-stock recipients** are `inventory.view` holders with access to the location. Warehouses have no per-user assignment, so only org-wide roles are notified for them. Note that `inventory.view` includes Cashier in the matrix; the notification permission (`notifications.view`) is narrower, so a cashier can receive rows but cannot open the inbox.
- **Table name caveat.** The table is `notifications` as specified, but `User` and `CustomerAccount` both use Laravel's `Notifiable` trait. Nothing calls `$user->notifications` today (there was no Laravel notifications migration either), but that accessor would now hit this table and fail on the missing `notifiable_*` columns. Recorded in `deferred-work.md`.
- **Config dot-notation trap.** Type keys contain dots (`portal_order.paid`), so `config('notifications.type_channels.'.$type)` silently misses; the map is indexed directly instead.

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Notification|Permission'` -- expected: pass
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Queue worker running; pay a portal order / mark ready → see Admin Notifications and mail log

## Suggested Review Order

**Dispatcher**

- notify() + after-commit delivery job
  [`NotificationService.php:1`](../../backend/Modules/Notifications/app/Services/NotificationService.php#L1)

- Channel adapters (mail / in-app / SMS stub)
  [`MailChannel.php:1`](../../backend/Modules/Notifications/app/Channels/MailChannel.php#L1)

**Triggers**

- Low stock, portal paid, ready/ship wires
  [`LowStockNotifier.php:1`](../../backend/Modules/Inventory/app/Services/LowStockNotifier.php#L1)

**Inbox**

- Staff list/mark-read + Admin UI
  [`NotificationController.php:1`](../../backend/Modules/Notifications/app/Http/Controllers/Api/NotificationController.php#L1)
  [`NotificationsView.vue:1`](../../frontend/admin/src/views/NotificationsView.vue#L1)

**Tests**

- Triggers + inbox matrix
  [`NotificationTriggersTest.php:1`](../../backend/tests/Feature/NotificationTriggersTest.php#L1)
