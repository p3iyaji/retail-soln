# POS SPA (Story 4.3)

Vue 3 + TypeScript + Vite + Tailwind CSS 4. Consumes `/api/v1` with Sanctum Bearer tokens (`retail_soln_pos_token`). Sends `X-Store-Id` after store selection. **No Inertia.** PWA: static `manifest.webmanifest` only (no service worker — Epic 5).

## Run

```bash
# terminal 1 — API
cd backend && php artisan serve --port=8001

# terminal 2 — POS
cd frontend/pos
cp -n .env.example .env
npm install
npm run dev
```

Open http://localhost:5174 — Vite proxies `/api` to `VITE_PROXY_TARGET` (default `http://localhost:8001`).

## Build

```bash
npm run build
```

## Shell notes

- Shortcuts (floor): F2 search · F4 customer · F8 discount · F9 pay · Esc close stub
- Terminal label: `localStorage` key `retail_soln_pos_terminal` (chrome editable)
- Session: placeholder until story 4.7 — no `/pos/sessions` calls
