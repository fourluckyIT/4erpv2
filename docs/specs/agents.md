# Adaptive 4ERP — agents.md (Pure Requirements, No Platform Assumptions)

## 0) Goal
Build a Job-centric Service Operations ERP for a small team (multi-hat roles), covering:
- Job (Lumpsum / Dayrent / Manpower)
- Planning & booking (anti-double-booking)
- Site compliance (cert requirements + expiry + training)
- Procurement & receiving (GR)
- Stock states (Main / In-transit / Vehicle / Site / Consume) with append-only ledger
- Packing (Package)
- Dispatch (Trip/Route) with OWN vs OUTSOURCE
- Reservation (anti-duplicate allocation by qty & serial)
- Timesheet/Attendance with Supervisor approval → HR workflow
- Notifications (In-app + LINE OA) + optional Calendar events
- Auditability: no-delete after Draft, reversal-only corrections

## 1) Non-Negotiable Rules (Hard Constraints)
### 1.1 Job-centric
- Every operational record that impacts stock, manpower time, cost, dispatch, or billing must reference a Job.
- If an exception exists (e.g., replenishment stock), it must be explicit and auditable.

### 1.2 Draft is flexible; after lockpoint is immutable
- Draft: editable and deletable (within permission).
- After Confirm/Submit: no direct overwrite of historical truth.
- Corrections must be done using Void/Reversal/Correction documents with full traceability.

### 1.3 No-delete after Draft
- Any entity beyond Draft cannot be deleted.
- “Deleting” is represented by status changes (Void/Cancelled) plus reversal entries if needed.

### 1.4 Append-only ledgers & histories
- Stock ledger is append-only.
- Status history is append-only.
- Audit logs are append-only.
- Never update/delete rows that represent historical truth.

### 1.5 Reservation prevents double allocation
- Allocation to Packing/Trip must create Reservations.
- Available = OnHand - Reserved.
- Serialized items: a serial can exist in only one active allocation/reservation at a time.
- Reservation operations must be transactional to prevent race conditions.

### 1.6 Compliance gate
- Site can require certs with expiry.
- Manpower assignment and/or Trip confirmation/dispatch must be blocked or require approval if cert is invalid for the job/trip date.

### 1.7 Timesheet workflow is enforced
- Supervisor must verify check-in/check-out for all workers daily.
- Once confirmed, timesheet auto-queues to HR.
- After submission to HR, corrections must follow correction/reversal workflow.

### 1.8 Auditability
- Every write and every key transition must be logged: who/when/what/from→to/why.
- Critical actions require reason text.

## 2) Role Model (Small Team Friendly)
### 2.1 Roles are permissions, not people
- One person can hold multiple roles.
- Each Job/Trip must have explicit assignments (Owner/Supervisor/Planner/etc.) even if same person.

### 2.2 Minimal role set
- ADMIN: system configuration
- OPS_APPROVER: approvals, overrides, reversal permissions
- OFFICE: create/manage jobs, docs, procurement coordination
- WAREHOUSE: receiving/issuing/stock actions (may be held by Site Supervisor)
- SITE_SUPERVISOR: site confirmations, evidence, timesheets
- HR: cert/training, timesheet review
- FINANCE: invoicing, credit notes, financial corrections (optional early stage)
- AUDITOR: read-only

## 3) Events & Notifications
### 3.1 In-app notifications are the primary system of record
### 3.2 LINE OA is push-only (role-bound recipients)
- Send only actionable or urgent events (approvals, overdue, exceptions, readiness blocks).
- Must include short summary + link to record (if URL exists).

### 3.3 Optional calendar events
- Job start/end, Trip schedule, Training sessions, Timesheet cutoff.

## 4) Quality Bar (Definition of Done for each feature)
A feature is “done” only if:
- Data model exists (entities + relationships).
- Permissions defined.
- Server-side rules/validation enforced.
- Audit + status history recorded.
- At least one happy-path and one failure-path test scenario documented.
- Relevant notification routing is defined (if the feature creates actionable work).

## 5) Deliverables expected from implementation agents
- Data model/schema proposal
- Screens/flows description (not UI design, but user actions)
- Validation rules & lockpoints
- Audit/event logging points
- Notification routing table
- Test scenarios (manual + automated outline)
