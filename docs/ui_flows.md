# UI Flow Map - 4ERP v2

**Generated**: 2026-01-18  
**Branch**: phase5-v2

## Overview

This document maps the key user journeys through the ERP system per `agents.md` requirements.

---

## 1. Job Lifecycle Flow (Happy Path)

```mermaid
flowchart TD
    A[Dashboard] --> B[Jobs List]
    B --> C[Create Job]
    C --> D[Job View: Draft]
    D --> E{Submit}
    E --> F[Job: Submitted]
    F --> G{Approve - MGR}
    G --> H[Job: Approved]
    H --> I[Planning View]
    I --> J{Create Plan}
    J --> K[Plan View]
    K --> L{Assign Resources}
    L --> M[Job: Planned]
    M --> N[Create Route]
    N --> O[Route View]
    O --> P{Upload 4 Photos}
    P --> Q{Dispatch}
    Q --> R[Route: Dispatched]
    R --> S{Work Complete}
    S --> T{Upload Return Photos}
    T --> U{Mark Returned}
    U --> V[Route: Returned]
    V --> W{WH Receive}
    W --> X[Route: WHReceived]
    X --> Y[Job: Accounting Ready]
```

---

## 2. Procurement Flow (PR → PO → GR)

```mermaid
flowchart TD
    A[Procurement Hub] --> B[PR List]
    B --> C[Create PR]
    C --> D[PR View: Pending]
    D --> E{Approve PR - PUR/MGR}
    E --> F[PR: Approved]
    F --> G{Create PO from PR}
    G --> H[PO View: Pending]
    H --> I{Approve PO - MGR}
    I --> J[PO: Approved]
    J --> K{Create GR}
    K --> L[GR Form: Receive Items]
    L --> M{Submit GR}
    M --> N[GR: Received]
    N --> O[Stock Updated]
```

---

## 3. Conflict Detection Flow

```mermaid
flowchart TD
    A[Plan View] --> B[Assign Serial/Vehicle]
    B --> C{Check Conflicts}
    C -->|No Conflict| D[Booking Created]
    C -->|Conflict Detected| E[Show Conflict Warning]
    E --> F{Choose Different Resource}
    F --> B
    D --> G[Assignment Confirmed]
```

---

## 4. Evidence Photo Flow

```mermaid
flowchart TD
    A[Route View: Confirmed] --> B[Upload Dispatch Photos]
    B --> C{4 Photos?}
    C -->|No| B
    C -->|Yes| D[Dispatch Button Enabled]
    D --> E{Dispatch}
    E --> F[Route: Dispatched]
    F --> G[Upload Receive Photos]
    G --> H{4 Photos?}
    H -->|No| G
    H -->|Yes| I[InProgress Button Enabled]
    I --> J[Route: InProgress]
    J --> K[Upload Return Photos]
    K --> L{4 Photos?}
    L -->|No| K
    L -->|Yes| M[Return Button Enabled]
    M --> N[Route: Returned]
```

---

## 5. Accounting AR Flow

```mermaid
flowchart TD
    A[Job: Accounting Ready] --> B[Create Invoice]
    B --> C[Invoice: Draft]
    C --> D{Issue Invoice}
    D --> E[Invoice: Issued]
    E --> F[Record Payment]
    F --> G{Partial?}
    G -->|Yes| H[Invoice: Partial Paid]
    H --> F
    G -->|No| I[Invoice: Paid]
    
    E --> J{Void Invoice?}
    J --> K[Create Credit Note]
    K --> L[Invoice: Voided]
    
    F --> M{Reverse Payment?}
    M --> N[Reversal Entry]
    N --> O[Balance Restored]
```

---

## 6. Reversal Patterns

### Stock Movement Reversal
```mermaid
flowchart LR
    A[Original Movement] --> B[Reversal Request]
    B --> C[Create Reversal Entry]
    C --> D[Link: reverse_of_id]
    D --> E[Stock Restored]
    A -.->|Immutable| A
```

### Payment Reversal
```mermaid
flowchart LR
    A[Original Payment] --> B[Reversal Request]
    B --> C[Create Reversal Entry]
    C --> D[Original Status: Reversed]
    D --> E[Invoice Balance Updated]
    A -.->|Preserved| A
```

---

## 7. RBAC Visibility Matrix

| Screen | ADM | SAL | PLN | PUR | HR | WH | ACC | MGR |
|--------|-----|-----|-----|-----|----|----|-----|-----|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Jobs | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Planning | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Routes | ✅ | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| PR Create | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ |
| PR Approve | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| PO Create | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| PO Approve | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| GR Create | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Invoice Issue | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| Payment Record | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| WH Receive | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Manpower Reg | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Admin | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Audit Logs | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

---

## 8. Key Alternative Paths

### 8.1 Job Extend
```
Job: Approved → Extend Request → Job: Extended
```

### 8.2 Job Void
```
Job: Any Status (before Dispatched) → Void by MGR → Job: Voided
```

### 8.3 Route Cancel
```
Route: Confirmed/Dispatched → Cancel by MGR → Route: Cancelled → Bookings Released
```

### 8.4 Invoice Credit Note (Void)
```
Invoice: Issued → Void Request → Credit Note Created → Invoice: Voided
```

---

## 9. UI Gaps vs agents.md

| agents.md Requirement | UI Status | Gap |
|-----------------------|-----------|-----|
| M4 Warehouse Receive | ❌ No UI | Need screen for WH staff |
| M4 Stock Movements | ❌ No UI | Need movements list/view |
| M5 Invoice Management | ❌ No UI | Need invoice CRUD pages |
| M5 Payment Management | ❌ No UI | Need payment record page |
| M6 Policy Viewer | ❌ No UI | Nice-to-have for admin |
| 4-Photo Enforcement | ✅ In Route | Photos via Route view |
| Conflict Guard | ✅ In Planning | Visual feedback on conflicts |
| Audit Trail | ✅ Admin Logs | Full audit viewer exists |
