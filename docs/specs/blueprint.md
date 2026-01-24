# Adaptive 4ERP — blueprint.md (Pure Requirements, No Platform Assumptions)

## 1) System Overview
A Job-centric service operations system for industrial services with:
- Three Job types: LUMPSUM, DAYRENT, MANPOWER
- Stock tracking across multiple states (Main, In-transit, Vehicle, Site, Consume)
- Packing + multi-trip dispatch, own or outsourced
- Reservation to prevent double allocation of limited stock
- Site compliance (certs with expiry + training)
- Timesheet/attendance with Supervisor approval → HR workflow
- Strict auditability: no delete after Draft, reversal-only corrections

## 2) Key Concepts
### 2.1 Job-centric linkage
Every operational object must reference Job:
- procurement & receiving
- stock movements
- packing and trips
- manpower assignments
- timesheets
- invoicing/costing references

### 2.2 Immutable history after confirmation
All confirmed data is immutable.
Corrections are represented by new records (void/reversal/correction) linked to originals.

### 2.3 Small-team operating model
A user can hold multiple roles.
Work ownership is expressed via Job/Trip assignments.

## 3) Core Modules & Requirements

## 3.1 Master Data
Entities:
- Customer, Contact, Site
- Service Types / Scope templates (optional)
- Items with types: DEVICE(serial), EQUIPMENT(returnable), CONSUMABLE, SERVICE/MANPOWER, OTHER
- UOM, categories
- Locations/warehouses with types: MAIN, IN_TRANSIT, VEHICLE, SITE, CONSUME
- Vehicles (OWN), Vendors (OUTSOURCE), Suppliers
- Employees/Workers, skills/roles
- Cert Types, Site Requirements, Training definitions

Global requirements:
- Naming series for all major documents
- Attachments supported on all major entities

## 3.2 Job Management
Job fields (minimum):
- job_no (auto numbering)
- customer, site
- job_type: LUMPSUM/DAYRENT/MANPOWER
- title/description
- planned_start/end, actual_start/end
- status workflow
- assignments: job_owner, site_supervisor, planner, logistics_owner, approver (can be same person)
- scope lines (services/rates/notes)
- quotation reference: quotation_no (manual entry only; PDF attachment optional)

Job workflow (minimum):
- DRAFT → PLANNED → IN_PROGRESS → DONE → INVOICED → CLOSED
- VOID/CANCELLED

Lockpoints:
- DRAFT: editable/deletable
- PLANNED: limited edits per policy
- IN_PROGRESS+: changes must go through Change Request
- DONE/INVOICED+: only reversal/correction allowed

## 3.3 Change Requests (CR)
CR types:
- EXTEND_DAYS / REDUCE_DAYS
- CHANGE_SCOPE
- CHANGE_TRIP/ROUTE
- OTHER

CR must include:
- job_id
- requested change details
- reason
- impact assessment (manpower, stock, cost, invoice)
- approval workflow
- application record (when/how applied)

Applying CR:
- updates current plan fields
- creates history records
- never erases original history

## 3.4 Planning & Booking
Manpower planning:
- define required manpower (count/skill) by date range
- assign individuals to job dates
- prevent double-booking across jobs

Resource booking (optional extension):
- book vehicles/equipment similarly

Compliance gate:
- site has cert requirements by role/skill and minimum count
- employee cert has expiry and attachments
- block assignment or require override approval if not compliant for the job date

## 3.5 Procurement & Receiving (GR)
Procurement documents:
- request/purchase documents must reference job (or explicit replenishment policy)

Receiving:
- record received items
- enforce serial capture for DEVICE
- allow discrepancy handling (short/over/damaged)
- after confirmation, receiving is immutable; corrections via reversal

Service/Manpower purchasing:
- recorded as job cost without affecting stock ledger

## 3.6 Stock & Ledger
Stock states:
- MAIN / IN_TRANSIT / VEHICLE / SITE / CONSUME

Stock ledger:
- append-only movement entries
- on-hand derived from ledger (or rebuildable summaries)
- movements must reference job and source document (trip/package/etc.)

Core movements:
- MAIN → IN_TRANSIT → VEHICLE → SITE
- Return: SITE/VEHICLE → IN_TRANSIT → MAIN
- Consumption: SITE → CONSUME

Returnable equipment:
- track overdue returns and discrepancies

## 3.7 Packing (Package)
Package requirements:
- package_no (auto numbering, QR optional)
- job_id, site_id
- status: DRAFT/SEALED/ALLOCATED/DISPATCHED/RECEIVED/VOID
- package_lines: item, type, qty, serial list (if required)
- attachments for packing list / evidence

Rules:
- serialized items cannot exist in two active packages/allocations
- unseal requires permission and audit

## 3.8 Dispatch (Trip/Route)
Trip requirements:
- trip_no (auto numbering)
- job_id
- dispatch_date
- route definition (simple code or stop list)
- vehicle_mode: OWN or OUTSOURCE
- vehicle or vendor reference
- from_location, to_location
- status: DRAFT/CONFIRMED/DISPATCHED/ARRIVED/CLOSED/VOID
- linked packages/items

Confirm gate:
- reservation satisfied (or approved shortage)
- compliance satisfied (or approved override)
- required docs complete (optional checklist)

Dispatch/Arrival actions:
- dispatch triggers stock movement to IN_TRANSIT/VEHICLE
- arrival triggers site receiving confirmation

Outsource requirements:
- vendor, contact, and reference docs (attachment or reference number)

## 3.9 Reservation (Anti-duplicate allocation)
Reservation requirements:
- created when allocating stock to package/trip
- references: job, source location, item, qty, serial (optional), package/trip
- status: DRAFT/CONFIRMED/FULFILLED/VOID

Rules:
- Available = OnHand - Reserved
- block over-allocation
- serialized exclusivity
- transactional operations
- release reservations when draft allocations are changed/cancelled
- reversals required for confirmed/fulfilled corrections

## 3.10 Site Execution & Evidence
- site receiving confirmations
- daily reports (optional)
- acceptance / service report (document or attachment)
- photos and files can attach anywhere

## 3.11 Timesheet / Attendance
Timesheet Sheet:
- one sheet per job/site/date/team (policy configurable)
- contains all workers for that day
- fields: check-in/out, breaks, hours, OT, exceptions, notes
- attachments: photo of paper (transition), signed documents

Workflow:
- DRAFT (editable)
- CONFIRMED_BY_SUPERVISOR (locked)
- SUBMITTED_TO_HR (auto)
- HR_RECEIVED / HR_REVIEWED / PAYROLL_READY
- RETURNED (with reason) for correction request
- VOID (reversal/correction linkage)

Rules:
- supervisor must confirm
- after HR submission, corrections require correction/reversal workflow
- timesheet feeds job costing and (optionally) invoicing for dayrent/manpower

## 3.12 Finance Minimum (Optional Early Stage, Required Eventually)
- record invoice references per job
- support credit note/reversal references
- job costing rollup: materials, labor (timesheets), outsource

## 4) Notifications & Calendar
### 4.1 Notification engine
Must support:
- event log (append-only)
- routing rules: by role + by assignment (job owner/site supervisor/etc.)
- channel: in-app + LINE OA
- delivery status + retries
- preferences (mute non-critical)

### 4.2 Actionable event categories (minimum)
- approvals required (CR, shortage, overrides)
- receiving completed/discrepancy
- reservation conflict/shortage
- trip confirm/dispatch/arrival/delay
- timesheet cutoff overdue / HR queue events
- cert expiry warnings/blockers
- job start reminders/overdue

### 4.3 Calendar events (optional)
- job start/end
- trip schedule
- training sessions
- timesheet cutoff

## 5) Security & Audit
- role-based permissions
- assignment-based ownership checks
- append-only audit logs for all key actions
- status history per entity
- no-delete policy beyond Draft

## 6) Reporting & Dashboards (Minimum)
- job pipeline + overdue
- stock by state + job/site filters
- reservation conflicts/shortage
- trip readiness (stock + compliance + docs)
- timesheet pending submission / pending HR review
- compliance dashboard (cert expiring)
- job cost snapshot (especially Lumpsum)

## 7) Test Scenarios (Acceptance)
Minimum manual scenarios:
1) Job created (3 types) → planned → in progress → done
2) Receiving stock with serial enforcement and discrepancy handling
3) Packing + reservation prevents double allocation (qty & serial)
4) Trip confirm gate blocks without reservation/compliance
5) Dispatch → arrival → site receive → return/consume
6) Timesheet daily submit → supervisor confirm → HR queue → return & correction workflow
7) No-delete after Draft and reversal-only correction verified

Non-functional:
- works with small team and multi-role users
- multi-device access with consistent data
- audit trails complete and searchable
