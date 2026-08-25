---
name: retail-soln
description: Multi-store retail platform — Admin, Store Manager, and POS surfaces. Vue 3 + Tailwind; no Inertia. Fast-path draft from proposal/PRD with [ASSUMPTION] tags.
status: final
updated: 2026-08-24
sources:
  - docs/proposal.md
  - _bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/prd.md
colors:
  background: '#F4F6F8'
  foreground: '#12181F'
  muted: '#E8ECF0'
  muted-foreground: '#5C6B7A'
  card: '#FFFFFF'
  card-foreground: '#12181F'
  border: '#D5DCE3'
  primary: '#0B6E4F'
  primary-foreground: '#F5FFF9'
  accent: '#C45C26'
  accent-foreground: '#FFF8F3'
  destructive: '#B42318'
  destructive-foreground: '#FFF5F5'
  success: '#0B6E4F'
  warning: '#B45309'
  warning-foreground: '#FFFBEB'
  online: '#0B6E4F'
  offline: '#B45309'
  syncing: '#1D4E89'
  sync-error: '#B42318'
  pos-chrome: '#1A2330'
  pos-chrome-foreground: '#E8EEF4'
  pos-total: '#0B6E4F'
typography:
  display:
    fontFamily: 'Outfit'
    fontSize: 32px
    fontWeight: '600'
    lineHeight: '1.15'
    letterSpacing: '-0.02em'
  heading:
    fontFamily: 'Outfit'
    fontSize: 20px
    fontWeight: '600'
    lineHeight: '1.25'
  body:
    fontFamily: 'IBM Plex Sans'
    fontSize: 15px
    fontWeight: '400'
    lineHeight: '1.45'
  label:
    fontFamily: 'IBM Plex Sans'
    fontSize: 13px
    fontWeight: '500'
    lineHeight: '1.3'
  mono:
    fontFamily: 'IBM Plex Mono'
    fontSize: 13px
    fontWeight: '500'
    lineHeight: '1.3'
  pos-total:
    fontFamily: 'Outfit'
    fontSize: 40px
    fontWeight: '700'
    lineHeight: '1.1'
    letterSpacing: '-0.03em'
rounded:
  sm: 4px
  md: 8px
  lg: 12px
  none: 0px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  pos-gap: 12px
components:
  button-primary:
    background: '{colors.primary}'
    foreground: '{colors.primary-foreground}'
    radius: '{rounded.md}'
  button-pos-pay:
    background: '{colors.pos-total}'
    foreground: '{colors.primary-foreground}'
    radius: '{rounded.md}'
    minHeight: 56px
  sync-badge:
    radius: '{rounded.sm}'
    fontFamily: '{typography.label.fontFamily}'
  cart-line:
    background: '{colors.card}'
    border: '{colors.border}'
---

# DESIGN.md — retail-soln

## Brand & Style

**retail-soln** is a serious retail operations platform. Visual language is **operational clarity**: forest green for go/success/online, warm burnt-orange accent for attention (not decoration), charcoal POS chrome for speed surfaces. No consumer-app playfulness. `[ASSUMPTION]` Brand name remains "retail-soln" until a go-to-market name is chosen.

Admin / Store Manager: light workspace, dense tables OK, calm chrome.  
POS: high-contrast, large hit targets, total always dominant, chrome dark band for status — content area stays light for readability under store lighting.

Avoid: purple gradients, cream+terracotta editorial look, glow effects, pill-cluster dashboards, emoji in UI chrome.

## Colors

- **Primary forest (`#0B6E4F`)** — primary actions, online, success, pay CTA
- **Accent ember (`#C45C26`)** — promotions badge, “needs attention” (not errors)
- **Warning amber (`#B45309`)** — OFFLINE / pending sync exception
- **Sync blue (`#1D4E89`)** — SYNCING only
- **Destructive (`#B42318`)** — void, fail, SYNC ERROR
- **Surfaces** — cool gray page `#F4F6F8`, white cards, charcoal POS chrome `#1A2330`

## Typography

- **Outfit** — display, headings, POS total
- **IBM Plex Sans** — body, labels, forms
- **IBM Plex Mono** — SKUs, barcodes, money columns in tables

## Layout & Spacing

- Admin content max width: fluid to `1440px`; tables full bleed within main pane
- POS: fixed three-zone layout (see EXPERIENCE.md) — no max-width constraint; fill viewport
- Spacing scale: 4 / 8 / 16 / 24 / 32; POS internal gap 12px

## Elevation & Depth

- Admin: 1px border + subtle shadow on modals only
- POS: flat; borders for cart lines; no decorative shadows
- Sync badge: flat color chip on chrome

## Shapes

- Controls `8px`; POS pay button `8px` with min-height 56px
- No fully rounded pills for primary actions

## Components

- **Sync badge** — ONLINE / OFFLINE / SYNCING / SYNC ERROR with named color tokens
- **POS pay button** — full-width primary in payment rail
- **Cart line** — mono SKU, sans name, large qty stepper
- **Admin data table** — compact row height; sticky header

## Do's and Don'ts

| Do | Don't |
| --- | --- |
| Keep POS total largest element in viewport | Bury total under nav or stats |
| Show sync state always on POS chrome | Hide connectivity failures |
| Use mono for money/SKU | Use display serif on POS |
| One primary CTA per dialog | Stack multiple competing greens |
