# Customer Portal SPA

Vue 3 + Vite + TypeScript + Tailwind + Pinia customer-facing shop + account app (Stories 6.1–6.5).

**Dev URL:** http://localhost:5175

```bash
cd frontend/customer-portal
npm install
cp -n .env.example .env
npm run dev
```

API: `VITE_PROXY_TARGET` (default `http://127.0.0.1:8000`).

**Backend env (portal):**
- `CUSTOMER_PORTAL_ORGANIZATION_ID` — empty = first organization
- `CUSTOMER_PORTAL_DEFAULT_STORE_ID` — empty = first **active** store in that org (catalogue + default fulfilment store)
- `CUSTOMER_PORTAL_DELIVERY_FEE` — flat home-delivery fee (`DECIMAL` string; default `0.0000`; negative rejected)
- `CUSTOMER_PORTAL_RESERVATION_TTL` — unpaid order hold TTL in minutes (default `60`); scheduler runs `portal:expire-unpaid-reservations` every minute

**Stock reservation (Story 7.1) + pick/pack (Story 7.2) + ship/deliver (Story 7.3) + store pickup (Story 7.4):**
- Place-order dual-posts **available→reserved** via `InventoryLedgerService` (hard reject if insufficient available; no portal oversell)
- Pay success **keeps** the reserved hold
- Staff pack (`POST /api/v1/fulfilment/orders/{id}/pack` + `X-Store-Id` + `Idempotency-Key`, permission `fulfilment.pick`) **consumes** reserved (−reserved, `MovementType::Sale`) via `StockReservationService::consume` — does **not** touch available and does **not** call POS `SaleCompletionService`; status → `processing` (timeline “Picked & packed”)
- Staff ship home_delivery only (`POST /api/v1/fulfilment/orders/{id}/ship` + `Idempotency-Key`, permission `fulfilment.ship`): thin `portal_shipments` row + status → `out_for_delivery` — **no** ledger posts
- Staff deliver (`POST /api/v1/fulfilment/orders/{id}/deliver` + `Idempotency-Key`, `fulfilment.ship`): status → `delivered` — **no** ledger posts; pickup / click & collect ship rejected
- Staff ready for pickup store_pickup / click_collect (`POST /api/v1/fulfilment/orders/{id}/ready` + `Idempotency-Key`, `fulfilment.pickup`): status → `ready_for_pickup` — **no** ledger / shipment
- Staff collect (`POST /api/v1/fulfilment/orders/{id}/collect` + `Idempotency-Key`, `fulfilment.pickup`): status → `delivered` — **no** ledger; home_delivery ready/collect rejected
- Cancel unpaid/failed (`POST /api/v1/portal/orders/{id}/cancel`) or TTL expiry releases **reserved→available**
- Cancel sets order `status=cancelled` as the primary terminal signal; `payment_status` stays `awaiting_payment` / `failed` (no new payment enum without migration)
- Order detail shows **Cancel order** (confirm dialog) while `cancellable` (pending + awaiting_payment|failed) and displays `reservationExpiresAt`
- TTL job requires a running scheduler (`php artisan schedule:work` or cron). Docker Compose includes a `scheduler` service that runs `schedule:work` (low-stock hourly + unpaid reservation TTL every minute).

**Payments (AD-10):**
- Adapters: `manual` (local confirm), `stripe`, `paystack`
- Shoppers confirm manual pay via authenticated `POST /api/v1/portal/orders/{id}/confirm-payment`
- Provider-style manual webhook: `POST /api/v1/webhooks/payments/manual` requires `PAYMENT_MANUAL_WEBHOOK_SECRET` + header `X-Manual-Webhook-Secret` (not for browser checkout)
- Stripe/Paystack webhooks: signature verify + idempotent event storage; provider must match the order
- Without live keys, Stripe/Paystack return placeholder redirect URLs (include `orderId` + `payment_ref`); production fails closed with 503 if misconfigured
- `PORTAL_FRONTEND_URL` / `PAYMENT_ALLOWED_REDIRECT_ORIGINS` — success/cancel URLs must match allowlisted origins (no open redirects)

**Orders (Story 6.4):**
- Chrome **Orders** → `/shop/orders` (own orders only) → `/shop/orders/:id` with fulfilment-aware status timeline
- Paid (or post-confirmed) orders: **Download invoice** → HTML `invoice-{order_number}.html` from portal order (not POS `Sale`/`Invoice`)
- Confirmation deep-links to order detail
- Staff fulfilment status transitions: pack (7.2), ship/deliver home_delivery (7.3), ready/collect pickup & click_collect (7.4)

**Returns (Story 6.5):**
- Paid **delivered** orders: **Request return** → `/shop/orders/:id/return` (lines / qty / reason / notes)
- Creates portal return request only (`POST /api/v1/portal/return-requests` + `Idempotency-Key`) — does **not** call `SaleReturnService` or change inventory/payments
- Chrome **Returns** → `/shop/returns` list → `/shop/returns/:id` detail; order detail shows request status + “No refunds yet”

**Flows:** guest browse/search → register → verify → login → **add to cart** → `/shop/cart` → `/shop/checkout` → `/shop/confirmation?orderId=…` → **Orders** / invoice → (after delivery) **Request return**.
