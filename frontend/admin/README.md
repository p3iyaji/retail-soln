# Admin SPA (Story 1.6)

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens. **No Inertia.**

## Oversell exceptions (Story 5.6)

Nav **Oversells** → `/oversell-exceptions`. Lists pending `inventory_oversell_exceptions` for the selected store (`GET /sync/oversell-exceptions` + `X-Store-Id`) and **Approve** (`POST /sync/oversell-exceptions/{id}/approve`). Requires `inventory.approve-oversell`. Device Admin console remains Story 5.7.

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
