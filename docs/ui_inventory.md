# UI Inventory - 4ERP v2

**Generated**: 2026-01-18  
**Branch**: phase5-v2  
**Total Pages**: 46

## Navigation Structure

The navigation is role-based with RBAC checks in `includes/nav.php`.

### Top-Level Navigation

| Menu Item | URL | Visibility | Role Check |
|-----------|-----|------------|------------|
| Dashboard | `/4erpv2/index.php` | All logged-in | None |
| Jobs | `/4erpv2/modules/jobs/` | RBAC | `can('view', 'JOB')` |
| Planning | `/4erpv2/modules/planning/` | All logged-in | None |
| Logistics | Dropdown | All logged-in | None |
| Procurement | Dropdown | RBAC | `can('view', 'PR')` or `can('view', 'PO')` |
| Admin | Dropdown | Role | `isAdmin()` or `hasRole(ROLE_MANAGER)` |

---

## Screen Inventory by Module

### Auth Module
| Screen | URL | Title | Actions | Service |
|--------|-----|-------|---------|---------|
| Login | `/modules/auth/login.php` | Login | Form submit | Auth |
| Logout | `/modules/auth/logout.php` | - | Session destroy | Auth |

### Dashboard
| Screen | URL | Title | Actions | Service |
|--------|-----|-------|---------|---------|
| Dashboard | `/index.php` | Dashboard | View stats, Quick actions | Auth, RBAC |

### Jobs Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Jobs List | `/modules/jobs/` | Jobs | ADM, SAL, PLN, MGR | List, Filter |
| Create Job | `/modules/jobs/create.php` | Create Job | ADM, SAL, PLN | Form submit |
| View Job | `/modules/jobs/view.php?id=X` | {job_number} | ADM, SAL, PLN, MGR | View, Actions |
| Edit Job | `/modules/jobs/edit.php?id=X` | Edit {job_number} | ADM, SAL, PLN | Form submit |
| Jobs API | `/modules/jobs/api.php` | - | API | AJAX endpoints |

### Planning Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Planning List | `/modules/planning/` | Planning | ADM, PLN | List, Filter |
| Create Plan | `/modules/planning/create.php` | สร้าง Plan | ADM, PLN | Form submit |
| View Plan | `/modules/planning/view.php?id=X` | Plan #{number} | ADM, PLN | View, Assignments |

### Logistics Module

#### Routes
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Routes List | `/modules/logistics/routes/` | Routes | ADM, PLN, WH | List, Filter |
| Create Route | `/modules/logistics/routes/create.php` | สร้าง Route | ADM, PLN | Form submit |
| View Route | `/modules/logistics/routes/view.php?id=X` | Route: {number} | ADM, PLN, WH | Photos, Status |

#### Dispatch
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Dispatch List | `/modules/logistics/dispatch/` | Dispatch | ADM, PLN, WH | List |
| Create Dispatch | `/modules/logistics/dispatch/create.php` | สร้าง Dispatch Note | ADM, PLN | Form submit |
| View Dispatch | `/modules/logistics/dispatch/view.php?id=X` | Dispatch #{number} | ADM, PLN, WH | View |

### Procurement Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Procurement Hub | `/modules/procurement/` | Procurement | ADM, PUR, MGR | Overview |

#### Purchase Requests (PR)
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| PR List | `/modules/procurement/pr/` | Purchase Requests | ADM, PUR, MGR | List, Filter |
| Create PR | `/modules/procurement/pr/create.php` | สร้าง PR | ADM, PUR, SAL, PLN | Form submit |
| View PR | `/modules/procurement/pr/view.php?id=X` | PR: {number} | ADM, PUR, MGR | Approve, Actions |
| Edit PR | `/modules/procurement/pr/edit.php?id=X` | แก้ไข PR: {number} | ADM, PUR | Form submit |

#### Purchase Orders (PO)
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| PO List | `/modules/procurement/po/` | Purchase Orders | ADM, PUR, MGR, ACC | List, Filter |
| Create PO | `/modules/procurement/po/create.php` | สร้าง PO | ADM, PUR | Form submit |
| View PO | `/modules/procurement/po/view.php?id=X` | PO: {number} | ADM, PUR, MGR, ACC | Approve, GR |
| Manpower Register | `/modules/procurement/po/manpower.php` | ลงทะเบียนแรงงาน | ADM, HR | Form submit |

#### Goods Receipt (GR)
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| GR List | `/modules/procurement/gr/` | Goods Receipts | ADM, WH, PUR | List |
| Create GR | `/modules/procurement/gr/create.php` | รับสินค้า | ADM, WH | Form submit |
| View GR | `/modules/procurement/gr/view.php?id=X` | GR: {number} | ADM, WH, PUR | View |

### Master Data Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Master Hub | `/modules/master/` | Master Data | ADM | Overview |
| Customers | `/modules/master/customers.php` | Customers | ADM | CRUD |
| Suppliers | `/modules/master/suppliers.php` | Suppliers | ADM, PUR | CRUD |
| Items | `/modules/master/items.php` | Items | ADM, WH | CRUD |
| Serials | `/modules/master/serials.php` | Serial Numbers | ADM, WH | CRUD |
| Sites | `/modules/master/sites.php` | Sites | ADM | CRUD |
| People | `/modules/master/people.php` | People | ADM, HR | CRUD |

### Admin Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| Admin Dashboard | `/modules/admin/` | Admin Dashboard | ADM, MGR | Overview |
| Users | `/modules/admin/users.php` | User Management | ADM | CRUD |
| Roles | `/modules/admin/roles.php` | Roles & Permissions | ADM | View |
| Permissions | `/modules/admin/permissions.php` | Custom Permissions | ADM | CRUD |
| Doc Numbers | `/modules/admin/doc_numbers.php` | Document Numbers | ADM | View, Reset |
| LINE Bindings | `/modules/admin/line_bindings.php` | LINE Bindings | ADM | CRUD |
| Audit Logs | `/modules/admin/audit_logs.php` | Audit Logs | ADM, MGR | View, Filter |

### Warehouse Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| (No UI pages) | - | - | - | Service only |

### Accounting Module
| Screen | URL | Title | Roles | Actions |
|--------|-----|-------|-------|---------|
| (No UI pages) | - | - | - | Service only |

---

## Missing UI Screens (vs agents.md)

| Required Flow | Missing Screen | Recommendation |
|---------------|---------------|----------------|
| M4 Warehouse | Stock Movements List | Add `/modules/warehouse/movements.php` |
| M4 Warehouse | WH Receive UI | Add `/modules/warehouse/receive.php` |
| M5 Accounting | Invoice List | Add `/modules/accounting/invoices/` |
| M5 Accounting | Invoice View | Add `/modules/accounting/invoices/view.php` |
| M5 Accounting | Payment List | Add `/modules/accounting/payments/` |
| M5 Accounting | Credit Note View | Add `/modules/accounting/credit-notes/` |
| M6 RBAC | Policy Viewer | Add `/modules/admin/policy.php` |

---

## Dead Links (Nav -> Missing Page)

| Nav Link | Target | Status |
|----------|--------|--------|
| `/4erpv2/modules/pr/` | PR List | ⚠️ Wrong path (should be `/modules/procurement/pr/`) |
| `/4erpv2/modules/po/` | PO List | ⚠️ Wrong path (should be `/modules/procurement/po/`) |
| `/4erpv2/modules/auth/profile.php` | Profile | ❌ File not found |

---

## Orphan Screens (No Nav Link)

| Screen | URL | Notes |
|--------|-----|-------|
| Master Hub | `/modules/master/` | No direct nav, only via Admin link |
| GR Create/View | `/modules/procurement/gr/*` | Accessible from PO only |
| Route Create/View | `/modules/logistics/routes/*` | Accessible from Planning only |

---

## RBAC Integration Summary

- **Header checks**: `Auth::requireAuth()` on all pages
- **Nav visibility**: RBAC checks in `includes/nav.php`
- **Action gating**: `$rbac->can()` for buttons/forms
- **Policy layer**: `core/Policy.php` defines matrix
- **Enforcement**: Partial (UI visibility only, service-level checks vary)
