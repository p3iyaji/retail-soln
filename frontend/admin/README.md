# Admin SPA (Story 1.6)

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens. **No Inertia.**

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
