# agents.md
# 4ERP v2 — Agents & Operating Rules (Pure Requirements)
Version: 1.0
Date: 2026-01-24 (UTC+7)
Owner: JT

## 0) วัตถุประสงค์ของเอกสารนี้
กำหนด “ตัวละคร/บทบาท (agents)” ที่ใช้งานระบบ + กฎการทำงานระดับระบบที่ต้อง enforce
- ไม่ผูกกับภาษา/เฟรมเวิร์ก/ERP ใด ๆ
- ใช้เป็นกรอบสำหรับออกแบบ UI/Service/DB/Workflow

## 1) หลักการที่ห้ามละเมิด (Non-Negotiables)
1) **Job-centric**: ทุก record ที่กระทบ คน/ของ/เวลา/เงิน ต้องอ้างถึง Job (หรือประกาศว่าเป็น Non-Job เช่น replenishment และ audit ได้)
2) **Draft ยืดหยุ่น**: แก้ไขได้อิสระในสถานะ DRAFT เท่านั้น
3) **หลัง Confirm/Submit = Immutable**: ห้ามแก้ทับ ต้องใช้ “Correction / Reversal / Void” ที่อ้างอิงต้นฉบับ
4) **Append-only History**: audit logs, status history, stock ledger, financial ledger ต้องเป็น append-only
5) **No Delete หลัง Draft**: delete allowed เฉพาะ DRAFT และต้องมี audit; หลังจากนั้นใช้ VOID
6) **Reservation กันซ้ำ**: Qty/Serial ต้องกัน double-allocation แบบ transactional
7) **Compliance Gate**: ถ้าไม่ผ่าน requirement ของไซต์ ต้อง block หรือให้ override ผ่านการอนุมัติพร้อม audit
8) **Timezone Rule**: แสดงผลและการอ้างอิงเวลาเป็น UTC+7; การจัดเก็บให้สม่ำเสมอ (แนะนำเก็บ UTC แล้วแปลงแสดง หรือเก็บ UTC+7 แบบ fixed) แต่ต้อง “ชัดเจนทั้งระบบ”
9) **Traceability**: ทุก movement ของ stock/serial ต้อง trace กลับเอกสารต้นทางได้ (PR/PO/GR/PACK/TRIP/RETURN/ADJUST)

## 2) Agents (บทบาทผู้ใช้) และความรับผิดชอบ
### 2.1 Owner / Admin (เจ้าของ/ผู้ดูแล)
- สร้าง master data, ตั้งค่า numbering, สร้าง roles/permissions
- ตั้ง approval matrix และ policy override
- ดู dashboard/KPI และ audit trails
- จัดการการปิดงวด/การ lock ช่วงเวลา (ถ้ามี)

### 2.2 Sales / CS (รับงาน/ประสาน)
- สร้าง Job (DRAFT) + ใส่ข้อมูลลูกค้า/ไซต์/ช่วงเวลาคาดการณ์
- ใส่ `quotation_no` (เลขใบเสนอราคาแบบ manual) + แนบไฟล์ (optional)
- ขอเปลี่ยน scope/งบ/วันเวลา ผ่าน change request (ถ้ากระทบ planning/dispatch)

### 2.3 Planner (วางแผน)
- กำหนดช่วงเวลา job + วางแผน manpower/asset/consumable requirement
- ตรวจ booking conflicts (คน/asset/serial) และแก้ก่อนยืนยัน
- สร้าง requirement ที่จะไป procurement/warehouse (PR / reservation)

### 2.4 Supervisor (หัวหน้างานหน้างาน)
- ยืนยันการทำงานหน้างาน: attendance (check-in/out), OT, exceptions
- ยืนยันการรับของ/คืนของ ณ ไซต์ (site receiving/return)
- อนุมัติ/confirm Timesheet ของทีมรายวัน (ล็อก) แล้วส่ง HR อัตโนมัติ
- ขอ override ในเคส shortage/compliance พร้อมเหตุผล

### 2.5 Warehouse (คลัง)
- รับ PR/จัดทำ picking/packing
- รับของเข้า (GR) + ตรวจ qty/quality + บังคับ serial สำหรับอุปกรณ์ที่ต้องมี
- ทำ stock movements: issue/transfer/return/adjustment ตาม policy
- ทำ package confirmation และปล่อยของออก trip ตาม gate

### 2.6 Procurement (จัดซื้อ)
- สร้าง PR → PO → ติดตาม vendor → สนับสนุน GR
- กำหนด vendor outsource สำหรับ trip/transport หรือ resource ภายนอก
- จัดการ backorder/partial delivery

### 2.7 Driver / Dispatcher (ขนส่ง/Dispatch)
- สร้าง Trip/Route (own/outsource) + ผูก package/items
- ทำ dispatch/arrival confirmations
- บันทึกเหตุการณ์ระหว่างทาง (delay, damage, partial drop)

### 2.8 HR / Payroll
- รับ Timesheet ที่ supervisor confirm แล้ว
- ตรวจความผิดปกติ/คืนกลับเพื่อแก้ (returned)
- ส่งต่อ payroll_ready
- ดู compliance/cert validity ของพนักงาน

### 2.9 Finance / Accounting
- ออก invoice/credit note, รับชำระ, ติดตาม AR
- คุมต้นทุน job, ปิดงวด
- ตรวจการ override ที่กระทบต้นทุน/รายได้

### 2.10 Auditor / Read-only
- เข้าดูข้อมูล + audit trail ได้ แต่แก้ไม่ได้
- ตรวจการทำ reversal/void และ approval logs

## 3) Approval & Override Matrix (ต้องมี)
ระบบต้องรองรับ rule-based approval อย่างน้อย:
- Override shortage (dispatch ทั้งที่ reserved ไม่ครบ)
- Override compliance (assign คนที่ cert ไม่ผ่าน)
- Purchase/PO ตามวงเงิน
- Stock adjustment (เสียหาย/สูญหาย)
- Timesheet exception (OT เกิน threshold, missing check-out)

ทุก approval ต้องมี:
- ผู้ขอ, ผู้อนุมัติ, เวลา, เหตุผล, scope, เอกสารที่เกี่ยวข้อง, ผลกระทบ (cost/stock/time)

## 4) Notifications (ขั้นต่ำ)
- In-app notification สำหรับ event ที่ actionable
- LINE OA (หรือ channel ภายนอก) เป็น push-only สำหรับ urgent เช่น:
  - Trip dispatch/arrival, shortage gate, compliance block, timesheet pending confirm, PO overdue

## 5) Data Governance (บังคับใช้)
- Master data ต้องมี unique keys และ validation (customer/site/item/uom/serial rule/roles)
- เอกสารทุกประเภทมี numbering scheme ที่สม่ำเสมอ
- ไฟล์แนบต้องผูกกับ entity และมีสิทธิ์เข้าถึง

## 6) Acceptance Criteria ระดับระบบ (Definition of Done)
- ไม่มีทาง “แก้ทับ” record ที่ confirm/submit แล้ว
- ทุกการเปลี่ยนสถานะมี status_history append-only
- ทุก write มี audit_log (actor, action, before/after summary, correlation id)
- Reservation กันซ้ำได้จริง (พร้อม concurrent test)
- Traceability: เปิดจาก stock ledger ย้อนไปเอกสารต้นทางได้
- Timesheet ดึงรายชื่อ default จาก manpower ที่ถูก plan ไปในวันนั้นได้
