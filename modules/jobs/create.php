<?php
/**
 * Create Job
 * 4ERP - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();

// Check permission
$rbac = new RBAC();
if (!$rbac->can('create', 'JOB')) {
    setFlash('error', 'คุณไม่มีสิทธิ์สร้างงาน');
    redirect('index.php');
}

$db = getDB();
$jobModel = new Job();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    $data = [
        'customer_id' => (int) post('customer_id'),
        'site_id' => post('site_id') ?: null,
        'job_type' => post('job_type'),
        'scope_short' => sanitize(post('scope_short')),
        'scope_detail' => post('scope_detail'),
        'quotation_no' => sanitize(post('quotation_no')),
        'plan_start_date' => post('plan_start_date'),
        'plan_end_date' => post('plan_end_date'),
        'owner_sale_id' => (int) post('owner_sale_id'),
        'owner_planner_id' => post('owner_planner_id') ?: null,
        'contract_value' => (float) post('contract_value', 0),
        'budget' => (float) post('budget', 0),
    ];
    
    $result = $jobModel->create($data);
    
    if ($result['success']) {
        setFlash('success', 'สร้างงาน ' . $result['job_number'] . ' เรียบร้อย');
        redirect('view.php?id=' . $result['id']);
    } else {
        setFlash('error', $result['error']);
    }
}

// Get dropdown data
$customers = $db->query("SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();
$sales = $db->query("
    SELECT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.code IN ('SAL', 'ADM') AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();
$planners = $db->query("
    SELECT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.code IN ('PLN', 'ADM') AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Create Job - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-plus-circle me-2"></i>สร้างงานใหม่
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Jobs</a></li>
                    <li class="breadcrumb-item active">Create</li>
                </ol>
            </nav>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Main Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูลหลัก
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ลูกค้า <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                            <select class="form-select" name="customer_id" id="customer_id" required>
                                <option value="">-- เลือกลูกค้า --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                                </div>
                                <div class="flex-shrink-0">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalCreateCustomer">
                                        <i class="bi bi-person-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Site</label>
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    <select class="form-select" name="site_id" id="site_id">
                                        <option value="">-- เลือก site --</option>
                                    </select>
                                </div>
                                <div class="flex-shrink-0">
                                    <button type="button" class="btn btn-outline-secondary" id="btnAddSite" disabled title="เลือกลูกค้าก่อน" data-bs-toggle="modal" data-bs-target="#modalCreateSite">
                                        <i class="bi bi-geo-alt-fill"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภทงาน <span class="text-danger">*</span></label>
                            <select class="form-select" name="job_type" required>
                                <option value="">-- เลือก --</option>
                                <option value="Lumpsum">Lumpsum (เหมา)</option>
                                <option value="Dayrent">Dayrent (รายวัน)</option>
                                <option value="Manpower">Manpower (คนงาน)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">อ้างอิงใบเสนอราคา</label>
                            <input type="text" class="form-control" name="quotation_no" placeholder="Q-2026-XXXXX">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดงาน (สั้น) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="scope_short" maxlength="500" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" name="scope_detail" rows="4"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Dates -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-calendar me-2"></i>ระยะเวลา
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันเริ่มงาน <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันสิ้นสุด <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_end_date" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Owners -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-people me-2"></i>ผู้รับผิดชอบ
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Sale <span class="text-danger">*</span></label>
                        <select class="form-select" name="owner_sale_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($sales as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $auth->getCurrentUserId() ? 'selected' : '' ?>>
                                <?= e($s['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planner</label>
                        <select class="form-select" name="owner_planner_id">
                            <option value="">-- ยังไม่กำหนด --</option>
                            <?php foreach ($planners as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Financial -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-currency-exchange me-2"></i>มูลค่า
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">มูลค่าสัญญา</label>
                        <input type="number" class="form-control" name="contract_value" step="0.01" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">งบประมาณ</label>
                        <input type="number" class="form-control" name="budget" step="0.01" value="0">
                    </div>
                </div>
            </div>
            
            <!-- Submit -->
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle me-2"></i>สร้างงาน
                </button>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="modalCreateCustomer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">สร้างลูกค้าใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">รหัสลูกค้า (ไม่บังคับ)</label>
                        <input type="text" class="form-control" id="cust_code" placeholder="เช่น CUST0003">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">ชื่อลูกค้า <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="cust_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ผู้ติดต่อ</label>
                        <input type="text" class="form-control" id="cust_contact_name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">โทรศัพท์</label>
                        <input type="text" class="form-control" id="cust_phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">อีเมล</label>
                        <input type="email" class="form-control" id="cust_email">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tax ID</label>
                        <input type="text" class="form-control" id="cust_tax_id">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ที่อยู่</label>
                        <textarea class="form-control" id="cust_address" rows="2"></textarea>
                    </div>
                </div>

                <!-- Site Creation Section -->
                <hr class="my-3">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="cust_has_sites">
                    <label class="form-check-label" for="cust_has_sites">เพิ่มไซต์ให้ลูกค้านี้</label>
                </div>
                <div id="cust_sites_section" class="d-none">
                    <div id="cust_sites_list">
                        <div class="site-row p-2 mb-2" data-idx="0">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <input type="text" class="form-control form-control-sm site-name" placeholder="ชื่อไซต์ *">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm site-map-url" placeholder="ลิงก์ Google Maps (ไม่บังคับ)">
                                </div>
                                <div class="col-md-1 d-flex align-items-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-site d-none" title="ลบ">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddMoreSite">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่มไซต์
                    </button>
                </div>

                <div id="cust_error" class="alert alert-danger mt-3 d-none"></div>
                <div id="cust_success" class="alert alert-success mt-3 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="btnCreateCustomerSave">
                    <i class="bi bi-check-circle me-1"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create Site for Existing Customer -->
<div class="modal fade" id="modalCreateSite" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">เพิ่มไซต์ใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ชื่อไซต์ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="site_name">
                </div>
                <div class="mb-3">
                    <label class="form-label">ลิงก์ Google Maps (ไม่บังคับ)</label>
                    <input type="text" class="form-control" id="site_map_url" placeholder="https://maps.google.com/...">
                </div>
                <div class="mb-3">
                    <label class="form-label">ที่อยู่ (ไม่บังคับ)</label>
                    <textarea class="form-control" id="site_address" rows="2"></textarea>
                </div>
                <div id="site_error" class="alert alert-danger d-none"></div>
                <div id="site_success" class="alert alert-success d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="btnCreateSiteSave">
                    <i class="bi bi-check-circle me-1"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<script>
if (typeof window.BASE_URL === 'undefined') {
    window.BASE_URL = '<?= BASE_URL ?>';
}

// Load sites when customer changes
const customerSelectEl = document.getElementById('customer_id');
customerSelectEl.addEventListener('change', function() {
    const customerId = this.value;
    const siteSelect = document.getElementById('site_id');
    
    siteSelect.innerHTML = '<option value="">-- กำลังโหลด... --</option>';
    
    if (!customerId) {
        siteSelect.innerHTML = '<option value="">-- เลือก site --</option>';
        return;
    }
    
    fetch(window.BASE_URL + '/modules/jobs/api.php?action=get_sites&customer_id=' + customerId)
        .then(r => r.json())
        .then(data => {
            siteSelect.innerHTML = '<option value="">-- เลือก site --</option>';
            data.forEach(site => {
                siteSelect.innerHTML += `<option value="${site.id}">${site.name}</option>`;
            });
        });

    // Enable Add Site button when customer selected
    document.getElementById('btnAddSite').disabled = false;
    document.getElementById('btnAddSite').title = 'เพิ่มไซต์';
});
if (customerSelectEl.value) {
    customerSelectEl.dispatchEvent(new Event('change'));
}

const modalCreateCustomerEl = document.getElementById('modalCreateCustomer');
const custError = document.getElementById('cust_error');
const custSuccess = document.getElementById('cust_success');
const btnCreateCustomerSave = document.getElementById('btnCreateCustomerSave');

function custShowError(msg) {
    custSuccess.classList.add('d-none');
    custError.textContent = msg;
    custError.classList.remove('d-none');
}

function custClearAlerts() {
    custError.classList.add('d-none');
    custSuccess.classList.add('d-none');
    custError.textContent = '';
    custSuccess.textContent = '';
}

modalCreateCustomerEl.addEventListener('show.bs.modal', () => {
    custClearAlerts();
});

btnCreateCustomerSave.addEventListener('click', async () => {
    custClearAlerts();

    const name = document.getElementById('cust_name').value.trim();
    if (!name) {
        custShowError('กรุณากรอกชื่อลูกค้า');
        return;
    }

    btnCreateCustomerSave.disabled = true;

    try {
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('code', document.getElementById('cust_code').value.trim());
        formData.append('name', name);
        formData.append('contact_name', document.getElementById('cust_contact_name').value.trim());
        formData.append('phone', document.getElementById('cust_phone').value.trim());
        formData.append('email', document.getElementById('cust_email').value.trim());
        formData.append('tax_id', document.getElementById('cust_tax_id').value.trim());
        formData.append('address', document.getElementById('cust_address').value.trim());

        // Collect sites if enabled
        const hasSites = document.getElementById('cust_has_sites').checked;
        if (hasSites) {
            const siteRows = document.querySelectorAll('#cust_sites_list .site-row');
            const sites = [];
            for (const row of siteRows) {
                const siteName = row.querySelector('.site-name').value.trim();
                const siteMapUrl = row.querySelector('.site-map-url').value.trim();
                if (siteName) {
                    sites.push({ name: siteName, map_url: siteMapUrl });
                }
            }
            if (sites.length === 0) {
                custShowError('กรุณากรอกชื่อไซต์อย่างน้อย 1 ไซต์');
                btnCreateCustomerSave.disabled = false;
                return;
            }
            formData.append('sites', JSON.stringify(sites));
        }

        const res = await fetch(window.BASE_URL + '/modules/jobs/api.php?action=create_customer', {
            method: 'POST',
            body: formData
        });

        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            custShowError(text || 'ไม่สามารถสร้างลูกค้าได้');
            return;
        }
        if (!res.ok || !data.success) {
            custShowError(data.error || 'ไม่สามารถสร้างลูกค้าได้');
            return;
        }

        const customer = data.customer;
        const customerSelect = document.getElementById('customer_id');
        const opt = document.createElement('option');
        opt.value = customer.id;
        opt.textContent = `${customer.code} - ${customer.name}`;
        customerSelect.appendChild(opt);
        customerSelect.value = String(customer.id);
        customerSelect.dispatchEvent(new Event('change'));

        const sitesCreated = data.sites_created || 0;
        custSuccess.textContent = `สร้างลูกค้า ${customer.code} เรียบร้อย` + (sitesCreated > 0 ? ` (พร้อม ${sitesCreated} ไซต์)` : '');
        custSuccess.classList.remove('d-none');

        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(modalCreateCustomerEl);
            modal.hide();
            // Reset form
            document.getElementById('cust_code').value = '';
            document.getElementById('cust_name').value = '';
            document.getElementById('cust_contact_name').value = '';
            document.getElementById('cust_phone').value = '';
            document.getElementById('cust_email').value = '';
            document.getElementById('cust_tax_id').value = '';
            document.getElementById('cust_address').value = '';
            document.getElementById('cust_has_sites').checked = false;
            document.getElementById('cust_sites_section').classList.add('d-none');
            resetSiteRows();
        }, 500);

    } catch (e) {
        custShowError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
    } finally {
        btnCreateCustomerSave.disabled = false;
    }
});

// Toggle site section
const custHasSitesEl = document.getElementById('cust_has_sites');
if (custHasSitesEl) {
    custHasSitesEl.addEventListener('change', function() {
        const section = document.getElementById('cust_sites_section');
        if (!section) {
            return;
        }
        if (this.checked) {
            section.classList.remove('d-none');
            updateRemoveButtons();
        } else {
            section.classList.add('d-none');
        }
    });
}

// Add more site row
let siteRowIdx = 1;
function addMoreSiteRow(e) {
    if (e && e.preventDefault) {
        e.preventDefault();
    }
    const container = document.getElementById('cust_sites_list');
    if (!container) {
        custShowError('ไม่พบพื้นที่เพิ่มไซต์');
        return;
    }
    const firstRow = container.querySelector('.site-row');
    let newRow;
    if (firstRow) {
        newRow = firstRow.cloneNode(true);
        newRow.dataset.idx = siteRowIdx++;
        newRow.querySelectorAll('input').forEach(input => {
            input.value = '';
        });
        const removeBtn = newRow.querySelector('.btn-remove-site');
        if (removeBtn) {
            removeBtn.classList.remove('d-none');
        }
    } else {
        newRow = document.createElement('div');
            newRow.className = 'site-row p-2 mb-2';
        newRow.dataset.idx = siteRowIdx++;
        newRow.innerHTML = `
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm site-name" placeholder="ชื่อไซต์ *">
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control form-control-sm site-map-url" placeholder="ลิงก์ Google Maps (ไม่บังคับ)">
                </div>
                <div class="col-md-1 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-site" title="ลบ">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `;
    }
    container.appendChild(newRow);
    updateRemoveButtons();
}

const btnAddMoreSiteEl = document.getElementById('btnAddMoreSite');
if (btnAddMoreSiteEl) {
    btnAddMoreSiteEl.addEventListener('click', addMoreSiteRow);
}

// Remove site row
document.getElementById('cust_sites_list').addEventListener('click', function(e) {
    if (e.target.closest('.btn-remove-site')) {
        e.target.closest('.site-row').remove();
        updateRemoveButtons();
    }
});

function updateRemoveButtons() {
    const rows = document.querySelectorAll('#cust_sites_list .site-row');
    rows.forEach((row, idx) => {
        const btn = row.querySelector('.btn-remove-site');
        if (rows.length > 1) {
            btn.classList.remove('d-none');
        } else {
            btn.classList.add('d-none');
        }
    });
}

function resetSiteRows() {
    const container = document.getElementById('cust_sites_list');
    container.innerHTML = `
        <div class="site-row p-2 mb-2" data-idx="0">
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm site-name" placeholder="ชื่อไซต์ *">
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control form-control-sm site-map-url" placeholder="ลิงก์ Google Maps (ไม่บังคับ)">
                </div>
                <div class="col-md-1 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-site d-none" title="ลบ">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    siteRowIdx = 1;
    updateRemoveButtons();
}

// === Create Site for Existing Customer ===
const modalCreateSiteEl = document.getElementById('modalCreateSite');
const siteError = document.getElementById('site_error');
const siteSuccess = document.getElementById('site_success');
const btnCreateSiteSave = document.getElementById('btnCreateSiteSave');

function siteShowError(msg) {
    siteSuccess.classList.add('d-none');
    siteError.textContent = msg;
    siteError.classList.remove('d-none');
}

function siteClearAlerts() {
    siteError.classList.add('d-none');
    siteSuccess.classList.add('d-none');
}

modalCreateSiteEl.addEventListener('show.bs.modal', () => {
    siteClearAlerts();
    document.getElementById('site_name').value = '';
    document.getElementById('site_map_url').value = '';
    document.getElementById('site_address').value = '';
});

btnCreateSiteSave.addEventListener('click', async () => {
    siteClearAlerts();

    const customerId = document.getElementById('customer_id').value;
    if (!customerId) {
        siteShowError('กรุณาเลือกลูกค้าก่อน');
        return;
    }

    const name = document.getElementById('site_name').value.trim();
    if (!name) {
        siteShowError('กรุณากรอกชื่อไซต์');
        return;
    }

    btnCreateSiteSave.disabled = true;

    try {
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('customer_id', customerId);
        formData.append('name', name);
        formData.append('map_url', document.getElementById('site_map_url').value.trim());
        formData.append('address', document.getElementById('site_address').value.trim());

        const res = await fetch(window.BASE_URL + '/modules/jobs/api.php?action=create_site', {
            method: 'POST',
            body: formData
        });

        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            siteShowError(text || 'ไม่สามารถสร้างไซต์ได้');
            return;
        }
        if (!res.ok || !data.success) {
            siteShowError(data.error || 'ไม่สามารถสร้างไซต์ได้');
            return;
        }

        const site = data.site;
        const siteSelect = document.getElementById('site_id');
        const opt = document.createElement('option');
        opt.value = site.id;
        opt.textContent = site.name;
        siteSelect.appendChild(opt);
        siteSelect.value = String(site.id);

        siteSuccess.textContent = `สร้างไซต์ "${site.name}" เรียบร้อย`;
        siteSuccess.classList.remove('d-none');

        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(modalCreateSiteEl);
            modal.hide();
        }, 500);

    } catch (e) {
        siteShowError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
    } finally {
        btnCreateSiteSave.disabled = false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
