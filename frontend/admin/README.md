# Admin SPA (Story 1.6)

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens. **No Inertia.**

## Fulfilment (Stories 7.2–7.3)

Nav **Fulfilment** → `/fulfilment`. Store-scoped portal orders (`GET /fulfilment/orders` + `X-Store-Id`). **Complete pack** (`fulfilment.pick`) consumes reserved stock. For **home_delivery**, **Ship** / **Mark delivered** (`fulfilment.ship` + `Idempotency-Key`) advance `processing` → `out_for_delivery` → `delivered` with optional carrier/tracking — no inventory ledger posts. Pickup / click & collect handoff is Story 7.4.

## Oversell exceptions (Story 5.6)

Nav **Oversells** → `/oversell-exceptions`. Lists pending `inventory_oversell_exceptions` for the selected store (`GET /sync/oversell-exceptions` + `X-Store-Id`) and **Approve** (`POST /sync/oversell-exceptions/{id}/approve`). Requires `inventory.approve-oversell`.

## Devices (Story 5.7)

Nav **Devices** → `/devices`. Register POS terminal UUIDs (`POST /devices`), list (`GET /devices`), rename / enable-disable (`PATCH /devices/{id}`). Requires `devices.manage` + `X-Store-Id`. Copy the UUID from the POS sync badge panel.

## Run

```bash
# terminal 1 — API (Docker or Herd)
cd backend && php artisan serve --port=8080
# or: docker compose up -d

# terminal 2 — admin
cd frontend/admin
cp -n .env.example .env
npm install
npm run dev
```

Open http://localhost:5173 — Vite proxies `/api` to `http://localhost:8080` by default.

## Build

```bash
npm run build
```
