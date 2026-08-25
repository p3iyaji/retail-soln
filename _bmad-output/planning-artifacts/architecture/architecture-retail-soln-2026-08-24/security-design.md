# Security Design — retail-soln

**Status:** Epic 0.4 companion  
**Date:** 2026-08-24  
**Governs:** AD-4, NFR-6–14

## Authentication

- Laravel Fortify for credential management; Sanctum personal access tokens for SPA/PWA
- 2FA required for Org Admin / Super Admin (configurable; default on for privileged roles)
- Session/token revocation on password reset and admin disable
- Rate-limit login and public auth endpoints

## Authorization

- Server-side Policies/Gates on every mutating and sensitive read endpoint
- Permission strings: `domain.action` (see `permission-matrix.md`)
- Store scope: `X-Store-Id` validated against `store_user`; org-wide roles may omit or pass any store
- Customer portal (Phase 7) uses separate guard/token ability set; **no admin route access**

## Data protection

- HTTPS only in production
- Secrets in env / secret manager — never in repo
- File uploads validated (MIME, size) → S3-compatible private buckets; signed URLs
- Encrypt sensitive columns where justified (e.g. payment tokens at rest via provider — do not store raw card PAN)
- DECIMAL money; no client-trusted totals without server recompute

## Payment security (AD-10)

- Stripe/Paystack webhooks: signature verify + idempotency key unique constraint
- Manual payments: permission-gated; audit logged
- Never log full webhook payloads with PII beyond need

## Audit

- Append-only `audit_logs` for: authz failures (optional sample), role changes, price changes, voids, refunds, inventory adjustments, PO approvals, oversell approvals
- No API to update/delete audit rows

## Threat notes

| Threat | Mitigation |
| --- | --- |
| Cross-store data leak | Global scopes + policy tests |
| Offline replay | Idempotency uuid + device binding |
| Privilege escalation | Permission matrix seed + policy tests |
| XSS/CSRF | SPA + Sanctum; sanitize; CSRF for cookie flows if any |
| Inventory fraud | Ledger + dual control on adjustments above threshold (configurable) |

## POS device

- Register device uuid; admin can disable
- ESC/POS print payloads generated server-side or from trusted sale snapshot — client cannot invent totals
