# Customer Portal SPA

Vue 3 + Vite + TypeScript + Tailwind + Pinia customer-facing shop + account app (Stories 6.1–6.4).

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
- Confirmation deep-links to order detail; **Request return** remains stubbed for 6.5
- Staff fulfilment status transitions stay Epic 7 (tests may set advanced statuses)

**Flows:** guest browse/search → register → verify → login → **add to cart** → `/shop/cart` → `/shop/checkout` → `/shop/confirmation?orderId=…` → **Orders** list / detail / invoice. Returns remain stubs (6.5).
