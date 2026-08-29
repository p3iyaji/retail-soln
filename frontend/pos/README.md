# POS SPA

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens (`retail_soln_pos_token`). Sends `X-Store-Id` after store selection. **No Inertia.**

## PWA / offline shell (Story 5.1) + local catalog cache (Story 5.2) + offline sale queue (Story 5.3) + push sync (Story 5.4) + pull cursors (Story 5.5) + sync status UI (Story 5.6) + device registration (Story 5.7)

- Install metadata: `public/manifest.webmanifest`
- Production builds register a service worker via `vite-plugin-pwa` (app-shell precache). **Dev (`npm run dev`) does not register a SW** so Vite HMR stays intact.
- Local store: Dexie IndexedDB (`src/db/posDb.ts`) — typed `products`, `prices`, `taxes`, `customers`, `inventory_snapshot`, `pos_config`; `pending_sales` uuid PK + envelope (schema v7); stable `deviceUuid` / `localSequence` / per-store `pullCursor` + `lastPullAt` in `sync_meta`.
- **Pull sync (5.5):** online store select / floor refresh / reconnect use `GET /sync/pull` (`src/sync/pullCache.ts`) — opaque cursor, capped pages, hasMore loop (page budget). Upserts Dexie; deletes/inactive remove sellable rows. Prefer store price rows; fallback to variant list price. `warmCache` remains for tests/legacy full replace only.
- **Online:** search + cart pricing stay on live `GET /products/search` and `POST /pricing/resolve`. Checkout uses `POST /pos/sales` with `body.uuid === Idempotency-Key`.
- **Offline:** search Dexie (`src/sync/localCatalog.ts`); cart lines from cached unit price + store tax (no promos). Inventory snapshot is advisory only. Completed cash/card checkouts enqueue into `pending_sales` (`src/sync/pendingSales.ts`) — local success “Queued — will sync when online”; no receipt/print API. Installment plan create and **held-sale completion** stay **online-only**.
- **Push sync (5.4):** on reconnect, `pushPendingSales` then `pullCache` (push errors do not skip pull). Dexie `syncStatus` → `pushing` → `synced`/`error`; badge SYNCING / SYNC ERROR. Unknown / disabled devices return 403 (`device_unregistered` / `device_disabled`) — register the UUID in Admin **Devices** first.
- **Sync status UI (5.6):** clickable chrome badge opens panel (last error, Dexie pending/error counts, **Retry** = `clearError` + shared `drainThenPull`). Offline banner once per ONLINE→OFFLINE (“Offline — sales will sync when connected”). Push `exceptionId` → non-blocking oversell notice with sale uuid/ref. SYNC ERROR never auto-dismisses. Server: `GET /sync/status`; Admin **Oversells** list + approve.
- **Device registration (5.7):** sync panel shows the full local `deviceUuid` (copyable) for Admin registration.
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
