# Epic 0.5 — Architecture Approval Gate

**Date:** 2026-08-24  
**Approver:** Paul  
**Result:** **APPROVED** (architecture spine previously approved; Epic 0 companions complete)

## Deliverables checklist

| # | Deliverable | Path | Status |
| --- | --- | --- | --- |
| 1 | Architecture spine | `ARCHITECTURE-SPINE.md` | ✅ final |
| 2 | Domain model | `domain-model.md` | ✅ |
| 3 | Database design | `database-design.md` | ✅ |
| 4 | API design | `api-design.md` | ✅ |
| 5 | Offline sync design | `offline-sync-design.md` | ✅ |
| 6 | Security design | `security-design.md` | ✅ |
| 7 | Permission matrix | `permission-matrix.md` | ✅ |

## Open questions disposition

| OQ | Decision | Locked in |
| --- | --- | --- |
| OQ-1 Gift cards | Deferred | PRD Non-Goals, AD deferred |
| OQ-2 Payments | Manual + Stripe + Paystack | AD-10 |
| OQ-3 Oversell | Allow negative + manager approval | AD-9, offline-sync-design |
| OQ-4 Loyalty | Rules engine in v1 | AD-11 |
| OQ-5 Accounting | Full ledger | AD-12 (UI may trail POS MVP) |
| OQ-6 Receipts | ESC/POS | AD-13 |

## Sequencing note (for your next permission gate)

- **MVP coding path:** Epic 1 → 4 (online POS) remains the release boundary
- **Loyalty engine (AD-11):** plan stories inside Epic 4 (or a thin Epic 4b) — not field-only
- **Accounting ledger (AD-12):** schema/hooks can start after Epic 1; full UI after POS MVP unless you redirect
- **No application code** until you explicitly permit Epic 1 / `bmad-build`

## Gate statement

Architecture Phase 1 (Epic 0) is **complete and approved**. Implementation of Laravel scaffold (Epic 1) is **blocked pending Paul's explicit go-ahead**.
