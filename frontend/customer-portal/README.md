# Customer Portal SPA

Vue 3 + Vite + TypeScript + Tailwind + Pinia customer-facing shop + account app (Stories 6.1–6.2).

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
- `CUSTOMER_PORTAL_DEFAULT_STORE_ID` — empty = first **active** store in that org (used for catalogue price + stock signal)

**Flows:** guest shop browse/search (`/shop`, `/shop/products/:id`) → register → verify email → login → profile / addresses. Cart/checkout/orders are not implemented yet (Stories 6.3–6.5). Add to cart is a disabled stub.
