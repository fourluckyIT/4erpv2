# ERP v2 - UI Design System

> Modern UI/UX Design สำหรับระบบ ERP v2

## 📁 โครงสร้างโฟลเดอร์

```
ui-design/
├── index.html              # หน้า Preview หลัก
├── README.md               # เอกสารนี้
├── assets/
│   ├── css/
│   │   └── main.css        # CSS Framework หลัก
│   ├── js/                 # JavaScript (ถ้ามี)
│   └── images/             # รูปภาพ
├── components/             # Reusable Components
├── layouts/
│   └── base.html           # Base Layout Template
└── pages/
    ├── dashboard/          # Dashboard ของแต่ละ Role
    │   ├── admin.html      # Admin Dashboard
    │   ├── sale.html       # Sale Dashboard
    │   ├── planner.html    # Planner Dashboard
    │   ├── warehouse.html  # Warehouse Dashboard
    │   ├── accountant.html # Accountant Dashboard
    │   └── manager.html    # Manager Dashboard
    ├── admin/
    │   └── visibility.html # Dashboard Visibility Control
    ├── jobs/
    │   └── list.html       # Jobs List Page
    ├── planning/
    ├── procurement/
    ├── warehouse/
    ├── accounting/
    ├── hrm/
    ├── logistics/
    └── timesheet/
```

## 🎨 Design System

### Color Palette

#### Primary Colors
- **Primary:** `#4F46E5` (Indigo)
- **Primary Hover:** `#4338CA`
- **Primary Light:** `#E0E7FF`

#### Role Colors
| Role | Code | Color | Background |
|------|------|-------|------------|
| Admin | ADM | `#7C3AED` | `#EDE9FE` |
| Sale | SAL | `#EC4899` | `#FCE7F3` |
| Planner | PLN | `#14B8A6` | `#CCFBF1` |
| Purchase | PUR | `#F97316` | `#FFEDD5` |
| HR | HR | `#8B5CF6` | `#EDE9FE` |
| Warehouse | WH | `#06B6D4` | `#CFFAFE` |
| Accountant | ACC | `#10B981` | `#D1FAE5` |
| Manager | MGR | `#EF4444` | `#FEE2E2` |

#### Status Colors
- **Success:** `#10B981`
- **Warning:** `#F59E0B`
- **Danger:** `#EF4444`
- **Info:** `#3B82F6`

### Typography
- **Font Family:** Inter (Google Fonts)
- **Base Size:** 14px

### Layout
- **Sidebar Width:** 260px (Collapsed: 72px)
- **Header Height:** 64px
- **Border Radius:** 8px / 12px

## 📱 Role-Based Dashboards

แต่ละ Role มี Dashboard ที่ปรับแต่งได้ โดย Admin สามารถควบคุม:

### 1. Admin Dashboard (`/pages/dashboard/admin.html`)
- System Health Overview
- User Management Stats
- Pending Approvals
- Audit Logs Timeline
- Quick Actions

### 2. Sale Dashboard (`/pages/dashboard/sale.html`)
- My Active Jobs
- Sales Revenue MTD
- Quick Actions (New Job, New Customer)
- Sales Trend Chart

### 3. Planner Dashboard (`/pages/dashboard/planner.html`)
- Calendar Widget
- Today's Timeline (Dispatches)
- Resource Availability
- Jobs Awaiting Planning

### 4. Warehouse Dashboard (`/pages/dashboard/warehouse.html`)
- Stock Overview
- Pending GR (Goods Receipt)
- Pending Returns
- Low Stock Alerts

### 5. Accountant Dashboard (`/pages/dashboard/accountant.html`)
- Revenue MTD
- Outstanding AR
- Ready to Invoice Jobs
- Recent Payments

### 6. Manager Dashboard (`/pages/dashboard/manager.html`)
- Executive KPIs
- Pending Approvals (All Types)
- Revenue Trend
- KPI Progress Bars

## 🎛️ Admin Visibility Control

ไฟล์: `/pages/admin/visibility.html`

Admin สามารถ:
1. **เลือก Role** - กำหนดค่าสำหรับแต่ละ Role
2. **Toggle Widgets** - เปิด/ปิด Widgets บน Dashboard
3. **กำหนดขนาด** - S (Small), M (Medium), L (Large)
4. **Preview** - ดู Dashboard Preview ก่อน Save

### Widget Categories
- **Statistics Widgets** - ตัวเลขสถิติ (Jobs, Revenue, etc.)
- **Table Widgets** - ตารางข้อมูล
- **Chart Widgets** - กราฟและแผนภูมิ
- **Quick Actions** - ปุ่มลัดสำหรับ Actions

## 🧩 Components

### Buttons
```html
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-outline">Outline</button>
<button class="btn btn-sm">Small</button>
<button class="btn btn-lg">Large</button>
```

### Badges
```html
<span class="badge badge-draft">Draft</span>
<span class="badge badge-submitted">Submitted</span>
<span class="badge badge-approved">Approved</span>
<span class="badge badge-in-progress">In Progress</span>
<span class="badge badge-paid">Paid</span>
```

### Role Badges
```html
<span class="role-badge role-adm">ADM</span>
<span class="role-badge role-sal">SAL</span>
<span class="role-badge role-pln">PLN</span>
```

### Cards
```html
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Title</h3>
    </div>
    <div class="card-body">Content</div>
    <div class="card-footer">Footer</div>
</div>
```

### Stat Cards
```html
<div class="stat-card">
    <div class="stat-icon primary">
        <i class="bi bi-briefcase"></i>
    </div>
    <div class="stat-content">
        <div class="stat-value">156</div>
        <div class="stat-label">Total Jobs</div>
    </div>
</div>
```

## 🚀 วิธีใช้งาน

1. เปิด Browser ไปที่:
   ```
   http://localhost:8888/4erpv2/ui-design/
   ```

2. เลือกดู Dashboard ของแต่ละ Role

3. ทดสอบ Admin Visibility Control

## 📋 Checklist สำหรับ Implementation

- [ ] นำ CSS Variables ไปใช้ใน Production
- [ ] Implement Dashboard Config API
- [ ] เพิ่ม Chart Libraries (Chart.js / ApexCharts)
- [ ] สร้าง Widget Components เป็น PHP Templates
- [ ] Implement AJAX สำหรับ Widget Loading
- [ ] Add Responsive Testing
- [ ] Accessibility Testing

## 📝 Notes

- Design นี้แยกจากโค้ดระบบจริง (`modules/`)
- ใช้สำหรับ Preview และออกแบบก่อนนำไป Implement
- ไม่มีผลต่อ Database หรือ Logic ใดๆ

---

*สร้างโดย Cascade AI - Jan 2026*
