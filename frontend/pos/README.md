# POS SPA

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens (`retail_soln_pos_token`). Sends `X-Store-Id` after store selection. **No Inertia.**

## PWA / offline shell (Story 5.1) + local catalog cache (Story 5.2)

- Install metadata: `public/manifest.webmanifest`
- Production builds register a service worker via `vite-plugin-pwa` (app-shell precache). **Dev (`npm run dev`) does not register a SW** so Vite HMR stays intact.
- Local store: Dexie IndexedDB (`src/db/posDb.ts`) — typed `products`, `prices`, `taxes`, `inventory_snapshot`, `pos_config` (schema v3); stable `deviceUuid` in `sync_meta`.
- **Cache warm** on store select / floor mount (`src/sync/cacheWarm.ts`): paginates existing `GET /products`, `GET /inventory/balances?storeId=`, `GET /stores/{id}` (taxConfig). Badge → SYNCING → ONLINE; failure → SYNC ERROR.
- **Online:** search + cart pricing stay on live `GET /products/search` and `POST /pricing/resolve`.
- **Offline:** search Dexie (`src/sync/localCatalog.ts`); cart lines from cached unit price + store tax (no promos). Inventory snapshot is advisory only.
- Sync badge (chrome): ONLINE / OFFLINE / SYNCING / SYNC ERROR — driven by `stores/sync.ts` + browser connectivity. Offline sale queue is Story 5.3 (not yet). Formal cursor pull is Story 5.5.

## Run

```bash
# terminal 1 — API
cd backend && php artisan serve --port=8000
# or: php artisan serve --port=8001  and set VITE_PROXY_TARGET accordingly

# terminal 2 — POS
cd frontend/pos
cp -n .env.example .env
npm install
npm run dev
```

Open http://localhost:5174 — Vite proxies `/api` to `VITE_PROXY_TARGET` (default `http://127.0.0.1:8000`; set `:8001` in `.env` if that port is busy).

## Build

```bash
npm run build
npm run preview   # exercise SW + offline shell
```

## Shell notes

- Shortcuts (floor): F2 search · F4 customer · F8 discount · F9 pay · Esc close stub
- Terminal label: `localStorage` key `retail_soln_pos_terminal` (chrome editable)
- Session: open/close via chrome **Session** control (`/pos/sessions`); Pay (F9) requires an open session
- Returns: chrome **Return** button (requires `sales.refund` — store manager+); lookup sale ID from receipt
