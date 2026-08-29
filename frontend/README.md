# Frontends

This repo has **three separate Vue 3 SPAs** — there is no shared app bundle at this level.

| App | Path | Dev URL | Command |
| --- | --- | --- | --- |
| **Admin** | `admin/` | http://localhost:5173 | `npm run dev` or `npm run dev:admin` |
| **POS** | `pos/` | http://localhost:5174 | `npm run dev:pos` |
| **Customer portal** | `customer-portal/` | http://localhost:5175 | `npm run dev:portal` |

## From `frontend/` (this folder)

```bash
npm run dev        # Admin SPA (default)
npm run dev:admin  # Admin SPA
npm run dev:pos    # POS SPA
npm run dev:portal # Customer portal SPA
```

First time per app:

```bash
cd admin && npm install && cp -n .env.example .env
cd ../pos && npm install && cp -n .env.example .env
cd ../customer-portal && npm install && cp -n .env.example .env
```

## From each app directory

```bash
cd admin && npm run dev              # :5173
cd pos && npm run dev                # :5174
cd customer-portal && npm run dev    # :5175
```

API must be running (`cd backend && php artisan serve --port=8001` or Docker nginx on `:8080`).

## Customer portal access

1. Start API (port `8000`/`8001` or Docker `:8080`).
2. Optional: set `CUSTOMER_PORTAL_ORGANIZATION_ID` in `backend/.env` (empty = first organization).
3. Start portal: `npm run dev:portal` from `frontend/` (or `cd customer-portal && npm run dev`).
4. Open **http://localhost:5175** — register, verify email (local/testing returns `verificationToken`), then manage profile/addresses.
5. Catalogue, cart, and orders are not implemented yet (Stories 6.2–6.5).

## POS access

1. Start API (port `8001` or Docker `:8080`).
2. Start POS: `npm run dev:pos` from `frontend/` (or `cd pos && npm run dev`).
3. Open **http://localhost:5174**
4. Log in with a user that has store access (e.g. seeded `admin@retail.local` / `password`, or a cashier assigned to a store).
5. Select a store on the picker screen — then you get the POS floor (cart, hold, pay).

## Troubleshooting

### `Request failed (502)` on login

Usually a **stale Vite dev server** is proxying to the wrong API port.

1. API must be up: `cd backend && php artisan serve` → **http://127.0.0.1:8000**
2. Check proxy: `curl http://localhost:5174/api/v1/health` (or `:5175` for portal) should return `200` (not `502`).
3. If `502`, stop the stale dev server and restart:
   ```bash
   lsof -ti :5174 | xargs kill   # or :5175 for portal
   cd frontend && npm run dev:pos   # or npm run dev:portal
   ```
4. Confirm the app `.env` has `VITE_PROXY_TARGET=http://127.0.0.1:8000` (same port as `artisan serve`).
