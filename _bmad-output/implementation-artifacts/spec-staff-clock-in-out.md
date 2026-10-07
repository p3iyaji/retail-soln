---
title: 'Staff clock-in at admin-defined locations'
type: 'feature'
created: '2026-10-07'
status: 'done'
review_loop_iteration: 0
baseline_commit: '1bf063c6779c06fe6b39255e703e31291fbf25cd'
context:
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Hours are not recorded, so admins cannot see who is working or calculate hourly pay, and a user could claim time somewhere they are not allowed to work.

**Approach:** Add Attendance. Admins define clock locations and assign users, each with an hourly rate. Assigned staff clock in only at an allowed location, then clock out. The admin sidebar gets Time clock for locations, assignments, who is in, and pay.

## Boundaries & Constraints

**Always:**
- nWidart module `Attendance`. `/api/v1` with `auth:sanctum` + `staff`. Rules in a domain service (AD-6). Money is `DECIMAL(15,4)` via bcmath, never float. Org from `OrganizationService::current()`. Policies enforce access (AD-4).
- Clock-in only at an active location the user is actively assigned to. One open shift per user. Clock-in snapshots `hourly_rate`. On clock-out, `gross_pay = (duration_minutes / 60) * snapshotted rate`.
- If latitude, longitude, and `radius_meters` are all set, clock-in requires coordinates inside that radius (haversine, meters). Clock-out needs no coordinates and closes the open shift.
- `attendance.manage`: SA, OA, SM (store-scoped). `attendance.clock`: SA, OA, SM, CA, IM, PO, AC, DS (own punches). SM manages only locations whose `store_id` they can access. Null `store_id` is org-wide (SA/OA only).

**Ask First:** Overtime, breaks, editing punch times, POS or portal clock UI, or paying wages. This story only records time and calculates pay.

**Never:** Inventory writes. Float money. Offline punch sync. Manual timestamp edits. Geofence on clock-out. Payroll payout or accounting journals.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Assigned clock-in | Active assignment, no geofence, not already in | Open shift, rate snapshotted | N/A |
| Unassigned or inactive | No active assignment, or location inactive | No shift | 422 |
| Outside geofence | Lat/lng/radius set; coords missing or outside | No shift | 422 |
| Already in | Open shift exists | No second shift | 422 |
| Clock-out pay | Rate 15.5000, 90 minutes | `duration_minutes` 90, `gross_pay` `23.2500`, closed | N/A |
| Clock-out idle | No open shift | No pay row | 422 |
| Forbidden | No `attendance.clock`, or SM touches another store | No write | 403 |
| Void | Manager voids a shift | `voided_at` set; excluded from pay totals | 403 without manage |

</frozen-after-approval>

## Code Map

- `backend/Modules/Settings/` — copy `module.json`, `composer.json`, `SettingsServiceProvider` (`loadMigrationsFrom`), `RouteServiceProvider` (prefix `api`), `routes/api.php` (`v1`, `auth:sanctum`, `staff`)
- `backend/modules_statuses.json` — `"Attendance": true`
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` — `$rows` L44; store-scope list L118. `attendance.manage` is store-scoped; `attendance.clock` is not
- `backend/app/Models/Concerns/HasRbac.php` — `hasPermission`, `canAccessStore`, `stores()`
- `backend/Modules/Organization/app/Services/OrganizationService.php` — `current()`
- `backend/Modules/Organization/app/Models/Store.php` — optional location `store_id`
- `backend/Modules/Audit/app/Services/AuditLogger.php` — `record()` on location, assignment, clock-in, clock-out, void
- `backend/tests/Feature/DeviceApiTest.php` — Sanctum `['staff']`, `stores()->attach($id, ['is_default' => true])`, `UserFactory` `orgAdmin` / `storeManager` / `cashier`, `StoreFactory`
- `frontend/admin/src/config/navigation.ts` — `navGroups` L14; add a group after `operations`. `AppShell.vue` already gates `permission`
- `frontend/admin/src/router/index.ts` — auth child routes. `src/api/client.ts` — `api.get/post/patch`. `ProductsView.vue` — inline table, form, error banner

## Tasks & Acceptance

**Execution:**
- [x] `backend/Modules/Authentication/app/Support/PermissionMatrix.php` — add `attendance.manage` (SA, OA, SM) and `attendance.clock` (SA, OA, SM, CA, IM, PO, AC, DS)
- [x] `backend/Modules/Attendance/` — Settings clone, enabled in `modules_statuses.json`. `clock_locations`: organization_id, nullable store_id, name, nullable address, nullable lat/lng `decimal(10,7)`, nullable `radius_meters`, `is_active`. `clock_location_assignments`: location_id, user_id, `hourly_rate` `decimal(15,4)`, `is_active`, unique pair. `clock_shifts`: user_id, location_id, snapshotted `hourly_rate`, `clock_in_at`, nullable `clock_out_at`, `duration_minutes`, `gross_pay` `decimal(15,4)`, `voided_at`
- [x] `backend/Modules/Attendance/app/Services/AttendanceService.php` — clock-in, clock-out, void, haversine, bcmath. HTTP only validates and delegates. Routes: location CRUD, assignment upsert, `POST /attendance/clock-in`, `POST /attendance/clock-out`, `POST /attendance/shifts/{id}/void`, `GET /attendance/shifts` (staff: own; managers: allowed locations), `GET /attendance/summary` (hours and gross pay, voids excluded)
- [x] `backend/tests/Feature/AttendanceApiTest.php` — one test per I/O row
- [x] `frontend/admin/src/config/navigation.ts` and `src/router/index.ts` — Workforce, "Time clock" → `/attendance`, permission `['attendance.manage', 'attendance.clock']`
- [x] `frontend/admin/src/views/AttendanceView.vue` — staff: pick an assigned location, clock in (send geolocation when it has a radius), clock out, see own shifts. Managers: create/deactivate locations, optional geofence and store, assign users with a rate, see who is in, filter timesheets, void a shift. Inline API errors.

**Acceptance Criteria:**
- Given an org admin, when they open admin, then Time clock is in the sidebar and they can create a location, assign a user with a rate, and that user can clock in only there.
- Given a closed non-voided shift, when a manager opens the timesheet, then hours and gross pay match the snapshotted rate and elapsed minutes.
- Given attendance tests, when `php artisan test --filter=AttendanceApiTest` runs, then they pass.
- Given admin, when `npm run build` runs in `frontend/admin`, then it exits 0.

## Spec Change Log

## Design Notes

Missing lat, lng, or radius means no coordinate check. Earth radius 6371000 m. Pay: `bcmul(bcdiv((string) $minutes, '60', 8), $rate, 4)`. JSON money is strings. Later rate changes do not rewrite closed shifts.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=AttendanceApiTest` -- expected: pass
- `cd frontend/admin && npm run build` -- expected: exit 0

**Manual checks (if no CLI):**
- Sign in as `admin@retail.local` / `password`, open Time clock, create a location, assign a cashier with a rate, clock them in and out, and confirm the menu and the pay figure.

## Suggested Review Order

**Clock rules**

- Clock-in locks the user, then snapshots the assignment rate.
  [`AttendanceService.php:164`](../../backend/Modules/Attendance/app/Services/AttendanceService.php#L164)

- Gross pay is decimal minutes times that snapshotted rate.
  [`AttendanceService.php:244`](../../backend/Modules/Attendance/app/Services/AttendanceService.php#L244)

- A geofence applies only when latitude, longitude, and radius are all set.
  [`ClockLocation.php:55`](../../backend/Modules/Attendance/app/Models/ClockLocation.php#L55)

- Store managers manage only locations on stores they can access.
  [`ClockLocation.php:62`](../../backend/Modules/Attendance/app/Models/ClockLocation.php#L62)

**Schema**

- Locations, assignments, and shifts keep money in decimal columns.
  [`2026_10_07_090000_create_attendance_tables.php:11`](../../backend/Modules/Attendance/database/migrations/2026_10_07_090000_create_attendance_tables.php#L11)

**Access**

- Manage is store-scoped; clock is granted to staff roles.
  [`PermissionMatrix.php:116`](../../backend/Modules/Authentication/app/Support/PermissionMatrix.php#L116)

**Admin screen**

- Time clock is a Workforce item gated by attendance permissions.
  [`navigation.ts:93`](../../frontend/admin/src/config/navigation.ts#L93)

- Deactivate and assign show only for locations the viewer can manage.
  [`AttendanceView.vue:604`](../../frontend/admin/src/views/AttendanceView.vue#L604)

**Tests**

- Clock rules, including pay after a later rate change, are asserted here.
  [`AttendanceApiTest.php:56`](../../backend/tests/Feature/AttendanceApiTest.php#L56)
