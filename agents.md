# agents.md — ERP v2 AI Operating Rules (LOCKED)

> This document is the single source of truth for how AI (and humans) must work on ERP v2.
> Any violation is forbidden. If a request conflicts with this file, STOP and ask for clarification.

---

## 0) Project Goal
Build an SAP-like, Job/PO-centric ERP with:
- full audit trail (every action, diff, request_id)
- strict status lockpoints
- no destructive deletes for financial/stock history
- multi-location stock (WH / Site / In-transit)
- route-based dispatch/receive/return with mandatory photo evidence
- RBAC with admin-configurable permissions

---

## 1) Non-Negotiable Rules (Hard Fences)

### 1.1 Strictly No DELETE / TRUNCATE (Immediate Violation)
Never use DELETE/TRUNCATE (or code that behaves like it) on:
- stock_movements
- ap_invoices
- ar_invoices
- payments

If a user asks to “remove history”, implement **void/reversal** instead.

### 1.2 Append-Only / Immutability
These are immutable after commit:
- financial & stock ledgers (e.g., stock_movements, payments, invoices)
- audit_logs / system_logs
Allowed updates: non-critical fields only (notes, attachments metadata), never amounts/qty/dates after commit.

### 1.3 Reversal Only (No History Rewrite)
Corrections must be done via:
- reverse dispatch / reverse receipt
- stock adjustment entries
- credit/debit notes
- reverse payments
Every reversal must reference the original record ID.

### 1.4 Status Lockpoints Are Law
Once Job reaches a lockpoint, core fields become read-only.
Any change must go through **Extension** (with approval + audit).

### 1.5 Mandatory Audit Trail
For every create/update/approve/status/print/export:
- write audit log with: who/when/action/entity/old/new/request_id
- store reason for cancel/void/override operations

---

## 2) Job Status Machine (LOCKED)

Statuses:
- Draft
- Submitted
- Approved
- Planned
- Dispatched
- In Progress
- Waiting for Return
- Returned
- WH Received
- POS Checked
- Accounting Ready
- Invoiced
- Paid / Partial Paid
- Closed
- Voided

Lockpoint rules:
- Approved: header locks (only Planner/Admin/Manager can change limited fields)
- Planned: plan changes only via Extension
- Dispatched: route confirmed with photos; no void after In Progress
- POS Checked: Job becomes Accounting-owned (ACC/PLN/ADM/MGR only)
- Closed: operational close allowed before Paid; finance can continue
- Waiting for Return: job is completed at site; only return routing and WH actions allowed

Cancel/Void rules:
- Cancel after Approved is allowed with reason + approval.
- After Dispatched: must perform step-by-step reversals; no direct void.

---

## 3) Core Business Rules (LOCKED)

### 3.1 Job Fields (minimum)
Required:
- customer + site
- job_type: Lumpsum / Dayrent / Manpower
- plan_start_date, plan_end_date
- owner_sale, owner_planner
- scope_short
Optional:
- quotation_no
- contract_value/budget
- pdf attachment
- required_certificates (references HRM cert catalog)

### 3.2 Items & Serial Rules
Item types:
- Device: serial required at GR, must return, condition check required, POS check required
- Equipment: serial required at GR, must return, loss allowed with note (no condition check required)
- Vehicle: license plate or internal ID required
- Consumable: not required to return; must record actual used at Return; overuse may be covered by retroactive Extension

Serial allocation:
- A serial can be active on **one job at a time** (no overlap).
- Transfer requires return/release from the previous job first.

### 3.3 Manpower from PO
- PO manpower is quantity-based.
- Individual person registration occurs at GR:
  - name (required), national_id (required), phone/email (optional)
  - source_po_no (required)
- Timesheet daily; approval chain:
  - Site Lead → HR → Manager (final)
- After final approval, timesheet is locked (adjustments only).

### 3.4 Route & Evidence Photos
Route rules:
- 1 job has many routes.
- 1 route = 1 vehicle + 1 supplier + 1 dispatch date.
- Route is editable until Confirmed.

Evidence rules (must enforce; missing evidence blocks status transition):
- Dispatch: atleast 1 photos per route
- Receive: atleast 1 photos per route
- Return: atleast 1 photos per route
- POS Check (Device only): atleast 1 photos around device

### 3.5 Cert Override
If required cert not met:
- Planning/dispatch may proceed only with Manager approval and audit reason.

### 3.6 Accounting
- Lumpsum = fixed contract (lump sum)
- 1 job can have multiple invoices
- partial payments supported
- tax/withholding supported
- invoice/payment are immutable; corrections use credit/debit/reverse payment

---

## 4) RBAC + Permission Matrix (LOCKED)

Roles:
- ADM, SAL, PLN, PUR, HR, WH, ACC, MGR

AI must follow the permission matrix below.
If implementation changes permissions, update docs and add tests.

### 4.1 JOB (Core)

| Status | View | Edit | Approve | Dispatch | Extend | Void | Close |
|------|------|------|---------|----------|--------|------|-------|
| Draft | ADM SAL PLN MGR | ADM SAL PLN | – | – | – | – | – |
| Submitted | ADM SAL PLN MGR | ADM SAL PLN | ADM MGR PLN | – | – | – | – |
| Approved | ADM PLN MGR ACC | PLN ADM | – | – | – | ADM MGR | – |
| Planned | ADM PLN MGR | PLN | – | PLN | PLN | – | – |
| Dispatched | ADM PLN MGR WH | – | – | – | PLN | – | – |
| In Progress | ADM PLN MGR | – | – | – | PLN | – | – |
| Waiting for Return | ADM PLN MGR WH | – | – | – | – | – | – |
| Returned | ADM PLN MGR WH | – | – | – | – | – | – |
| WH Received | ADM PLN MGR WH | – | – | – | – | – | – |
| POS Checked | ADM PLN MGR ACC | – | – | – | – | – | – |
| Accounting Ready | ADM ACC MGR | – | – | – | – | – | – |
| Invoiced | ADM ACC MGR | – | – | – | – | – | – |
| Paid / Partial | ADM ACC MGR | – | – | – | – | – | – |
| Closed | ADM ACC MGR | – | – | – | – | – | ADM MGR |
| Voided | ADM MGR | – | – | – | – | ADM MGR | – |

### 4.2 PR
- Create: ALL
- View/Approve: PUR ADM MGR
- Convert to PO: PUR

### 4.3 PO
- Create: PUR
- View: PUR ADM MGR ACC
- Approve: ADM MGR
- GR Register (Manpower): HR
- GR Receive (Item): WH
- Void: ADM MGR

### 4.4 Planning/Packing
- Plan manpower/device/consumable: PLN
- Override cert: MGR
- Confirm plan: PLN

### 4.5 Dispatch/Route
- Create/edit route (before confirm): PLN
- Confirm route (requires photos): PLN or WH
- Void route (before In Progress): ADM MGR

### 4.6 Return/WH
- Receive return (requires photos): WH
- WH Receive: WH
- Stock adjustment: WH submit; ADM/MGR approve

### 4.7 Timesheet
- Check-in/worklog: Site Lead
- Approve: HR
- Final approve: MGR

### 4.8 Accounting
- POS check (Device only): WH
- Accounting ready: ACC
- Create invoice: ACC
- Record payment: ACC
- Credit/debit: ACC submit; MGR approve

---

## 5) LINE OA Integration (LOCKED)

LINE OA is **notify only**.
- Notify users only if they are relevant by role/status.
- No financial edits/actions via LINE.
- No core data modifications via LINE.
- LINE events must be audited (who received/what was sent/request_id).

---

## 6) AI Work Mode (How to Generate Code Safely)

### 6.1 Small, Reviewable Changes
- Never implement multiple core modules in one step.
- Prefer small PR-sized chunks: 1 feature = 1 branch = 1 merge.

### 6.2 Before Any Change
AI must:
1) Identify affected modules/files.
2) State which rules/lockpoints apply.
3) Confirm no forbidden delete/updates.

### 6.3 After Any Change
AI must:
- list files changed
- provide brief reasoning
- ensure audit logging for new actions
- ensure status/permission checks exist
- recommend tests

---

## 7) Git Workflow (LOCKED)

- `main` must always be stable.
- Bugfix: create branch `fix/<name>`
- Feature: create branch `feat/<name>`
- Commit prefixes: `fix:`, `feat:`, `chore:`, `docs:`
- No direct commits to main for non-trivial changes.

---

## 8) Definition of Done (DoD)
A feature is done only if:
- permissions enforced (RBAC)
- audit logs written for all actions
- status lockpoint rules enforced
- evidence photo rules enforced where required
- no destructive deletes
- tests or at least smoke checks documented

---

## 9) If Conflict Happens
If a user request conflicts with this file:
- STOP
- explain the conflict
- propose a compliant alternative (void/reversal/extension/approval)
