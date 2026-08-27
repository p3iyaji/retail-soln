---
title: 'Story 4.3: POS Vue application shell'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '612508356a94e836c48fd3e8ec0eddf87e9ff4ef'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** There is no POS frontend—cashiers cannot open a store-scoped Vue app with Sanctum auth, chrome, or keyboard shortcuts required for fast selling (FR-15, UJ-1).

**Approach:** Scaffold `frontend/pos/` as a Vue 3 + Vite + Pinia SPA (mirror admin stack) with Sanctum login, store selection from `storeAccess`, three-zone POS chrome (search/cart placeholders), global F2/F4/F8/F9/Esc shortcuts, always-visible ONLINE sync badge, and a PWA web manifest (no offline SW). Cart/search/payment behavior stays stubbed for 4.4+.

## Boundaries & Constraints

**Always:**
- App lives under `frontend/pos/` — separate from Admin; Sanctum Bearer token (own storage key); send `X-Store-Id` on API calls after store is selected.
- Three-zone layout: top chrome (store, terminal label, cashier, sync badge ONLINE, session placeholder) · main search/scan zone · right cart rail (customer, total, Pay/Hold/Void placeholders). Tap targets ≥44px; Pay button visually dominant but disabled until later stories.
- Shortcuts (global when authenticated): F2 → focus search; F4 → customer stub; F8 → discount stub; F9 → payment stub; Esc → close/cancel stub. Prevent default browser chrome where handled.
- PWA: ship `manifest.webmanifest` (+ icons/theme) linked from `index.html`. No service worker / offline cache (Epic 5).
- Visual direction from UX spine: charcoal chrome, forest primary, ember accent; mono for money/SKU; sync badge shows text “ONLINE” (not color alone).
- Login gate: unauthenticated users see login; after login, require choosing an assigned store before the floor UI.

**Ask First:**
- Creating backend `terminals` CRUD in this story (default: skip—terminal is a local label/preference in chrome until Sales/4.7).
- Installing full `vite-plugin-pwa` / service worker (default: static manifest only).
- Implementing real cart/search/payment (default: labeled placeholders + shortcut-driven stub panels only).

**Never:**
- Inertia/Livewire; sharing Admin layout as the POS shell.
- Offline IndexedDB/sync queue (Epic 5).
- Atomic sales, sessions open/close APIs, or inventory posts (later stories).
- Hard-coding product prices in the shell.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Login success | Valid credentials | Token stored; proceed to store pick or floor | Show API error |
| No store access | User with empty `storeAccess` | Block floor; clear message | N/A |
| Store selected | Pick from `storeAccess` | Floor UI; `X-Store-Id` set | N/A |
| Shortcut F2 | On floor | Focus search input | N/A |
| Shortcut Esc | Stub panel open | Panel closes | N/A |
| Unauthenticated route | Hit `/` without token | Redirect login | N/A |
| Sync badge | Always | Visible label ONLINE | N/A |
| Manifest | Fetch `/manifest.webmanifest` | 200 JSON with name/icons/display | N/A |

</frozen-after-approval>

## Code Map

- `frontend/pos/.gitkeep` -- replace with full Vite Vue TS app
- `frontend/admin/package.json` + `vite.config.ts` + `src/api/client.ts` + `stores/auth.ts` + `router/index.ts` -- copy stack; POS port **5174**; token key `retail_soln_pos_token`; add `X-Store-Id` header from selected store
- `frontend/admin/src/style.css` -- start from CSS vars; override to POS DESIGN tokens (`#1A2330`, `#0B6E4F`, `#C45C26`)
- UX: `_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/EXPERIENCE.md` L44–103; `DESIGN.md` POS tokens; `mockups/pos-sale.html` for chrome/F-key strip reference
- Continuity 4.2: `/auth/login`, `/auth/me`, `storeAccess` on user; Walk-in/customers APIs exist but only stub-hook F4
- No `terminals` / `/pos/sessions` backend yet — chrome session shows “Session: —” / terminal local string
- Root `AGENTS.md` — POS under `frontend/pos/`; shortcuts F2/F4/F8/F9/Esc

## Tasks & Acceptance

**Execution:**
- [x] Scaffold `frontend/pos` (package.json, Vite, TS, Tailwind, env, scripts) aligned with admin versions
- [x] Auth client/store + login view + router guards; store picker; `X-Store-Id` on requests
- [x] POS floor shell: three zones, ONLINE sync badge, shortcut handler, stub panels for F4/F8/F9, disabled Pay/Hold/Void
- [x] `manifest.webmanifest` + icons link; README or comment for `npm run dev` on :5174
- [x] Smoke: `npm install && npm run build` in `frontend/pos`; manual shortcut checklist in Verification

**Acceptance Criteria:**
- Given a cashier with store access, when they log in and select a store, then the three-zone POS floor loads with ONLINE sync badge.
- Given the floor is open, when F2/F4/F8/F9/Esc are pressed, then search focuses or the matching stub panel opens/closes as specified.
- Given tap targets and Pay CTA, when viewing the shell, then primary actions meet ≥44px / dominant Pay affordance (disabled OK).
- Given the app is built, when opening the web manifest, then it is present and linked (no offline SW required).

## Spec Change Log

## Design Notes

**Stub panels:** Lightweight modal/drawer with title + “Coming in story 4.x” — Esc and backdrop close. F2 only focuses `#pos-search` input (empty results grid OK).

**Terminal:** `localStorage` key `retail_soln_pos_terminal` editable in chrome (text); display only.

**Session:** Placeholder text until 4.7; do not call `/pos/sessions`.

**Fonts:** Load Outfit + IBM Plex Sans/Mono via Google fonts or local if already patterned; keep build simple.

## Verification

**Commands:**
- `cd frontend/pos && npm install && npm run build` -- expected: success
- `cd frontend/admin && npm run build` -- expected: still green (untouched or incidental)

**Manual checks (if no CLI):**
- Login as cashier/admin → pick store → see chrome + ONLINE → press F2 (search focus), F9 (payment stub), Esc closes.

## Suggested Review Order

**Auth & store scope**

- Login / store pick / router gates + catch-all
  [`index.ts:34`](../../frontend/pos/src/router/index.ts#L34)

- Bearer token + `X-Store-Id` only for allowed stores
  [`client.ts:110`](../../frontend/pos/src/api/client.ts#L110)

**Floor shell**

- Three-zone chrome with ONLINE badge and Pay/Discount stubs
  [`PosFloorView.vue:92`](../../frontend/pos/src/views/PosFloorView.vue#L92)

- F2/F4/F8/F9/Esc shortcuts
  [`usePosShortcuts.ts:5`](../../frontend/pos/src/composables/usePosShortcuts.ts#L5)

**PWA**

- Static manifest linked from index
  [`index.html:6`](../../frontend/pos/index.html#L6)
