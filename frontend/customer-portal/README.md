# Customer Portal SPA

Vue 3 + Vite + TypeScript + Tailwind + Pinia customer-facing account app (Story 6.1).

**Dev URL:** http://localhost:5175

```bash
cd frontend/customer-portal
npm install
cp -n .env.example .env
npm run dev
```

API: `VITE_PROXY_TARGET` (default `http://127.0.0.1:8000`). Portal org: `CUSTOMER_PORTAL_ORGANIZATION_ID` in backend `.env` (empty = first organization).

Flows: register → verify email → login → profile / addresses. Catalogue/cart/orders are not in this story.
