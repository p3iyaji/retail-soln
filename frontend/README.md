# Frontends

This repo has **two separate Vue 3 SPAs** — there is no shared app bundle at this level.

| App | Path | Dev URL | Command |
| --- | --- | --- | --- |
| **Admin** | `admin/` | http://localhost:5173 | `npm run dev` or `npm run dev:admin` |
| **POS** | `pos/` | http://localhost:5174 | `npm run dev:pos` |

## From `frontend/` (this folder)

```bash
npm run dev        # Admin SPA (default)
npm run dev:admin  # Admin SPA
npm run dev:pos    # POS SPA
```

First time per app:

```bash
cd admin && npm install && cp -n .env.example .env
cd ../pos && npm install && cp -n .env.example .env
```

## From each app directory

```bash
cd admin && npm run dev   # :5173
cd pos && npm run dev     # :5174
```

API must be running (`cd backend && php artisan serve --port=8001` or Docker nginx on `:8080`).

## POS access

1. Start API (port `8001` or Docker `:8080`).
2. Start POS: `npm run dev:pos` from `frontend/` (or `cd pos && npm run dev`).
3. Open **http://localhost:5174**
4. Log in with a user that has store access (e.g. seeded `admin@retail.local` / `password`, or a cashier assigned to a store).
5. Select a store on the picker screen — then you get the POS floor (cart, hold, pay).

## Troubleshooting

### `Request failed (502)` on login

Usually a **stale Vite dev server** on port 5174 is proxying to the wrong API port.

1. API must be up: `cd backend && php artisan serve` → **http://127.0.0.1:8000**
2. Check proxy: `curl http://localhost:5174/api/v1/health` should return `200` (not `502`).
3. If `502`, stop all POS dev servers and restart one:
   ```bash
   lsof -ti :5174 | xargs kill
   cd frontend && npm run dev:pos
   ```
4. Confirm `frontend/pos/.env` has `VITE_PROXY_TARGET=http://127.0.0.1:8000` (same port as `artisan serve`).
5. If Vite moved to **5175** because 5174 was busy, use that URL or free 5174 first.

