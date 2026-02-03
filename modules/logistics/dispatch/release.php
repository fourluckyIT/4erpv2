<?php
/**
 * Release Routes - Multiple Route Release
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/EvidencePhoto.php';
require_once __DIR__ . '/../../../core/Policy.php';

// Helper function to upload simple photo
function uploadReleasePhoto(array $file, int $routeId): ?string {
    $uploadDir = __DIR__ . '/../../../uploads/evidence/' . $routeId . '/release/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'release_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $filePath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return 'uploads/evidence/' . $routeId . '/release/' . $filename;
    }
    return null;
}

$auth = new Auth();
$auth->requireAuth();

// Check permission (Route dispatch, not Job dispatch)
$rbac = new RBAC();
$policy = new Policy();
$canDispatchRoute = $policy->can(Policy::ROUTE_DISPATCH)
    || $rbac->can('dispatch', 'ROUTE')
    || $rbac->can('dispatch', 'JOB'); // fallback for legacy permissions
if (!$canDispatchRoute) {
    setFlash('error', 'คุณไม่มีสิทธิ์ปล่อย Route');
    redirect('index.php');
}

// Month filter (YYYY-MM)
$month = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

function getRouteStatusBadge(string $status): string {
    $map = [
        'Draft' => ['secondary', 'แบบร่าง'],
        'Confirmed' => ['info', 'ยืนยันแล้ว'],
        'Dispatched' => ['primary', 'ส่งของแล้ว'],
        'Received' => ['info', 'รับของแล้ว'],
        'InProgress' => ['warning', 'กำลังดำเนินการ'],
        'Returned' => ['info', 'รับคืนแล้ว'],
        'WHReceived' => ['success', 'คลังรับแล้ว'],
        'Cancelled' => ['danger', 'ยกเลิก'],
    ];
    [$class, $label] = $map[$status] ?? ['secondary', $status];
    return "<span class=\"badge bg-{$class}\">{$label}</span>";
}

$routeModel = new Route();
$db = getDB();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('release.php');
    }
    
    $selectedRoutes = post('selected_routes', []);
    $notes = post('notes', '');
    $releaseTime = post('release_time', date('Y-m-d H:i:s'));
    
    if (empty($selectedRoutes)) {
        setFlash('error', 'กรุณาเลือก Route อย่างน้อย 1 เส้นทาง');
        redirect('release.php');
    }
    
    try {
        $db->beginTransaction();
        
        $releasedCount = 0;
        $audit = new AuditLog();
        
        foreach ($selectedRoutes as $routeId) {
            $routeId = (int) $routeId;
            
            // Get route info
            $route = $db->prepare("SELECT r.*, p.job_id, j.job_number, c.name as customer_name, s.name as site_name 
                                FROM routes r 
                                JOIN plans p ON r.plan_id = p.id
                                JOIN jobs j ON p.job_id = j.id 
                                JOIN customers c ON j.customer_id = c.id 
                                LEFT JOIN sites s ON j.site_id = s.id 
                                WHERE r.id = ? AND r.status = 'Confirmed'");
            $route->execute([$routeId]);
            $routeData = $route->fetch();
            
            if (!$routeData) {
                continue;
            }
            
            // Handle photo upload for this route
            $photoPath = null;
            if (isset($_FILES['route_photo_' . $routeId]) && $_FILES['route_photo_' . $routeId]['error'] === UPLOAD_ERR_OK) {
                $photoPath = uploadReleasePhoto($_FILES['route_photo_' . $routeId], $routeId);
                if (!$photoPath) {
                    throw new Exception('ไม่สามารถอัปโหลดรูป Route ได้: ' . $routeData['route_number']);
                }
            }
            
            // Update route status to Dispatched
            $stmt = $db->prepare("UPDATE routes SET status = 'Dispatched', dispatched_at = ?, dispatched_by = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?");
            $noteAppend = $notes ? "\n[ปล่อย Route] " . $notes : '';
            if ($photoPath) {
                $noteAppend .= "\n[รูปปล่อย] " . $photoPath;
            }
            $stmt->execute([
                $releaseTime,
                $_SESSION['user_id'],
                $noteAppend,
                $routeId
            ]);
            
            // Audit log
            $audit->log(AUDIT_ACTION_UPDATE, 'ROUTE', $routeId, 
                ['status' => 'Confirmed'], 
                ['status' => 'Dispatched', 'dispatched_at' => $releaseTime, 'notes' => $notes, 'photo' => $photoPath]
            );
            
            $releasedCount++;
        }
        
        $db->commit();
        setFlash('success', "ปล่อย Route สำเร็จ {$releasedCount} เส้นทาง");
        redirect('release.php');
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

// Get confirmed routes ready for release - grouped by Job
$routes = $db->query("
    SELECT r.id, r.route_number, r.route_date, r.driver_name, r.driver_phone, r.notes, r.destination,
           p.job_id, p.plan_number, j.job_number, j.scope_short, 
           c.name as customer_name, s.name as site_name,
           sup.name as supplier_name,
           u.full_name as planner_name,
           (SELECT COUNT(*) FROM route_items ri WHERE ri.route_id = r.id) as item_count
    FROM routes r 
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id 
    JOIN customers c ON j.customer_id = c.id 
    LEFT JOIN sites s ON j.site_id = s.id
    LEFT JOIN suppliers sup ON r.supplier_id = sup.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE r.status = 'Confirmed' 
    ORDER BY j.job_number ASC, r.route_date ASC, r.route_number ASC
")->fetchAll();

// Group routes by Job
$jobRoutes = [];
foreach ($routes as $route) {
    $jobId = $route['job_id'];
    if (!isset($jobRoutes[$jobId])) {
        $jobRoutes[$jobId] = [
            'job_id' => $jobId,
            'job_number' => $route['job_number'],
            'scope_short' => $route['scope_short'],
            'customer_name' => $route['customer_name'],
            'site_name' => $route['site_name'],
            'routes' => []
        ];
    }
    $jobRoutes[$jobId]['routes'][] = $route;
}

$totalRoutes = count($routes);
$totalJobs = count($jobRoutes);

$summaryStmt = $db->prepare("
    SELECT
        COUNT(DISTINCT j.id) as planned_jobs,
        SUM(CASE WHEN r.status = 'Confirmed' THEN 1 ELSE 0 END) as ready_routes,
        SUM(CASE WHEN r.status IN ('Confirmed', 'Dispatched', 'InProgress') THEN 1 ELSE 0 END) as active_routes
    FROM routes r
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id
    WHERE r.status <> 'Draft'
      AND r.route_date BETWEEN :start AND :end
");
$summaryStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
$summary = $summaryStmt->fetch() ?: ['planned_jobs' => 0, 'ready_routes' => 0, 'active_routes' => 0];

$pipelineStmt = $db->prepare("
    SELECT
        j.id as job_id,
        j.job_number,
        j.scope_short,
        c.name as customer_name,
        MIN(r.route_date) as first_route_date,
        COUNT(*) as total_routes,
        SUM(CASE WHEN r.status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN r.status = 'Dispatched' THEN 1 ELSE 0 END) as dispatched_count,
        SUM(CASE WHEN r.status = 'InProgress' THEN 1 ELSE 0 END) as inprogress_count,
        SUM(CASE WHEN r.status = 'Returned' THEN 1 ELSE 0 END) as returned_count,
        SUM(CASE WHEN r.status = 'WHReceived' THEN 1 ELSE 0 END) as wh_received_count,
        SUM(CASE WHEN r.status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_count
    FROM routes r
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    WHERE r.status <> 'Draft'
      AND r.route_date BETWEEN :start AND :end
    GROUP BY j.id
    ORDER BY first_route_date ASC, j.job_number
");
$pipelineStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
$pipelineRows = $pipelineStmt->fetchAll();

$recentRoutes = $db->query("
    SELECT r.id, r.route_number, r.route_date, r.status, r.updated_at,
           p.job_id, j.job_number, c.name as customer_name
    FROM routes r
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    ORDER BY r.updated_at DESC, r.route_date DESC
    LIMIT 8
")->fetchAll();

$pageTitle = 'ปล่อย Route - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-send me-2" style="color: var(--primary);"></i>ปล่อย Route
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                <li class="breadcrumb-item active">ปล่อย Route</li>
            </ol>
        </nav>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-filter me-2"></i>กรองข้อมูล
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">เดือน (Route Date)</label>
                <input type="month" name="month" class="form-control" value="<?= e($month) ?>">
            </div>
            <div class="col-md-5">
                <div class="text-muted small mt-2">สรุปงานวางแผนที่รอปล่อย Route ตามเดือนที่เลือก</div>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="bi bi-search me-1"></i>กรอง
                    </button>
                    <a href="release.php" class="btn btn-outline-secondary">ล้าง</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="stat-cards mb-4">
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-briefcase"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format((int) ($summary['planned_jobs'] ?? 0)) ?></div>
            <div class="stat-label">Planned Jobs</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-send"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format((int) ($summary['ready_routes'] ?? 0)) ?></div>
            <div class="stat-label">Route พร้อมปล่อย</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-activity"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format((int) ($summary['active_routes'] ?? 0)) ?></div>
            <div class="stat-label">Active Routes</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-task me-2"></i>Planned Job (Route Release Pipeline)</span>
        <span class="badge bg-secondary"><?= count($pipelineRows) ?> งาน</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pipelineRows)): ?>
            <div class="text-center py-4 text-muted">ไม่พบงานในเดือนที่เลือก</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th class="text-center">Active Route</th>
                        <th>Customer</th>
                        <th>วันที่</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pipelineRows as $row): ?>
                    <?php
                        $activeRoutes = (int) ($row['confirmed_count'] + $row['dispatched_count'] + $row['inprogress_count']);
                    ?>
                    <tr>
                        <td>
                            <a href="../../jobs/view.php?id=<?= (int) $row['job_id'] ?>">
                                <strong><?= e($row['job_number']) ?></strong>
                            </a>
                            <?php if (!empty($row['scope_short'])): ?>
                            <div class="small text-muted"><?= e($row['scope_short']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary"><?= number_format($activeRoutes) ?></span>
                        </td>
                        <td><?= e($row['customer_name']) ?></td>
                        <td><?= formatDate($row['first_route_date']) ?></td>
                        <td class="d-flex flex-wrap gap-2">
                            <?php if ((int) $row['confirmed_count'] > 0): ?>
                                <span class="badge bg-info">Ready <?= (int) $row['confirmed_count'] ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['dispatched_count'] > 0): ?>
                                <span class="badge bg-primary">Dispatched <?= (int) $row['dispatched_count'] ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['inprogress_count'] > 0): ?>
                                <span class="badge bg-warning text-dark">In Progress <?= (int) $row['inprogress_count'] ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['returned_count'] > 0): ?>
                                <span class="badge bg-info">Returned <?= (int) $row['returned_count'] ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['wh_received_count'] > 0): ?>
                                <span class="badge bg-success">WH Received <?= (int) $row['wh_received_count'] ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['cancelled_count'] > 0): ?>
                                <span class="badge bg-danger">Cancelled <?= (int) $row['cancelled_count'] ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" id="releaseForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-gear me-2"></i>ตั้งค่าการปล่อย Route
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">วัน/เวลาที่ปล่อย <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" name="release_time"
                           value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea class="form-control" name="notes" rows="1"
                              placeholder="บันทึกเพิ่มเติมเกี่ยวกับการปล่อย Route นี้..."></textarea>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($jobRoutes)): ?>
    <div class="card mb-4">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mt-2">ไม่มี Route ที่รอปล่อยในขณะนี้</p>
            <div class="small">ต้องอยู่สถานะ <strong>Confirmed</strong> ก่อนจึงจะแสดงในหน้านี้</div>
            <a href="<?= BASE_URL ?>" class="btn btn-outline-primary mt-3">
                <i class="bi bi-house me-1"></i>กลับหน้าหลัก
            </a>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Job Cards with Routes -->
    <?php foreach ($jobRoutes as $jobId => $job): ?>
    <div class="card mb-4 job-card" data-job-id="<?= $jobId ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <div class="form-check">
                    <input class="form-check-input job-checkbox" type="checkbox" 
                           id="job_<?= $jobId ?>" data-job-id="<?= $jobId ?>">
                </div>
                <div>
                    <a href="../../jobs/view.php?id=<?= $jobId ?>" class="fw-bold text-primary fs-5">
                        <i class="bi bi-briefcase me-1"></i><?= e($job['job_number']) ?>
                    </a>
                    <div class="small text-muted"><?= e($job['scope_short']) ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end">
                    <div class="fw-semibold"><?= e($job['customer_name']) ?></div>
                    <?php if ($job['site_name']): ?>
                    <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($job['site_name']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="badge bg-info"><?= count($job['routes']) ?> routes</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="40px"></th>
                            <th>Route</th>
                            <th>วันที่</th>
                            <th>ปลายทาง</th>
                            <th>คนขับ</th>
                            <th>Items</th>
                            <th width="180px">รูปปล่อย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($job['routes'] as $route): ?>
                        <tr class="route-row" data-route-id="<?= $route['id'] ?>" data-job-id="<?= $jobId ?>">
                            <td>
                                <div class="form-check">
                                    <input class="form-check-input route-checkbox" type="checkbox" 
                                           name="selected_routes[]" value="<?= $route['id'] ?>"
                                           data-job-id="<?= $jobId ?>">
                                </div>
                            </td>
                            <td>
                                <a href="../routes/view.php?id=<?= $route['id'] ?>" class="fw-semibold">
                                    <?= e($route['route_number']) ?>
                                </a>
                                <div class="small text-muted"><?= e($route['supplier_name'] ?? '-') ?></div>
                            </td>
                            <td>
                                <div><?= formatDate($route['route_date']) ?></div>
                            </td>
                            <td><?= e($route['destination'] ?? '-') ?></td>
                            <td>
                                <?php if ($route['driver_name']): ?>
                                <div><?= e($route['driver_name']) ?></div>
                                <?php if ($route['driver_phone']): ?>
                                <div class="small text-muted"><?= e($route['driver_phone']) ?></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= (int)$route['item_count'] ?></span></td>
                            <td>
                                <div class="route-photo-upload" data-route-id="<?= $route['id'] ?>">
                                    <input type="file" class="d-none" 
                                           id="route_photo_<?= $route['id'] ?>" 
                                           name="route_photo_<?= $route['id'] ?>" 
                                           accept="image/*"
                                           onchange="previewPhoto(this, <?= $route['id'] ?>)">
                                    <label for="route_photo_<?= $route['id'] ?>" 
                                           class="btn btn-sm btn-outline-secondary w-100 photo-label">
                                        <i class="bi bi-camera"></i> เลือกรูป
                                    </label>
                                    <div id="photo_preview_<?= $route['id'] ?>" class="mt-2"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Action Buttons -->
    <div class="card action-bar" style="position: sticky; bottom: 1rem; z-index: 100; box-shadow: var(--shadow-lg);">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label" for="selectAll">เลือกทั้งหมด</label>
                    </div>
                    <span class="text-muted">|</span>
                    <span class="fw-semibold">
                        เลือกแล้ว <span id="selectedCount" class="badge bg-primary">0</span> Route
                    </span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                        <i class="bi bi-x-circle me-1"></i>ล้าง
                    </button>
                </div>
                <button type="submit" class="btn btn-success btn-lg" id="releaseBtn" disabled>
                    <i class="bi bi-send me-1"></i>ปล่อย Route ที่เลือก
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</form>

<div class="card mt-4 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Recent Routes</span>
        <a href="../routes/index.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-list me-1"></i>ดูทั้งหมด
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentRoutes)): ?>
            <div class="text-center text-muted py-4">ยังไม่มี Route ในระบบ</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th>Job</th>
                        <th>ลูกค้า</th>
                        <th>วันที่</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRoutes as $r): ?>
                    <tr>
                        <td>
                            <a href="../routes/view.php?id=<?= (int)$r['id'] ?>">
                                <strong><?= e($r['route_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../../jobs/view.php?id=<?= (int)$r['job_id'] ?>">
                                <?= e($r['job_number']) ?>
                            </a>
                        </td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td><?= formatDate($r['route_date']) ?></td>
                        <td><?= getRouteStatusBadge($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
<?php if (!empty($routes)): ?>
function previewPhoto(input, routeId) {
    const preview = document.getElementById('photo_preview_' + routeId);
    const label = input.nextElementSibling;
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 100px;">
                <button type="button" class="btn btn-sm btn-outline-danger mt-1 w-100" 
                        onclick="removePhoto(${routeId})">
                    <i class="bi bi-trash"></i> ลบรูป
                </button>
            `;
            label.classList.add('d-none');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto(routeId) {
    const input = document.getElementById('route_photo_' + routeId);
    const preview = document.getElementById('photo_preview_' + routeId);
    const label = input.nextElementSibling;
    
    input.value = '';
    preview.innerHTML = '';
    label.classList.remove('d-none');
}

// Select all functionality
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.route-checkbox');
    const jobCheckboxes = document.querySelectorAll('.job-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    jobCheckboxes.forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

// Job checkbox - select all routes in that job
document.querySelectorAll('.job-checkbox').forEach(jobCb => {
    jobCb.addEventListener('change', function() {
        const jobId = this.dataset.jobId;
        const routeCheckboxes = document.querySelectorAll(`.route-checkbox[data-job-id="${jobId}"]`);
        routeCheckboxes.forEach(cb => cb.checked = this.checked);
        updateSelectedCount();
        updateSelectAllState();
    });
});

// Update selected count
function updateSelectedCount() {
    const checked = document.querySelectorAll('.route-checkbox:checked');
    const count = checked.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('releaseBtn').disabled = count === 0;
    
    // Update job checkbox states
    document.querySelectorAll('.job-checkbox').forEach(jobCb => {
        const jobId = jobCb.dataset.jobId;
        const allRoutes = document.querySelectorAll(`.route-checkbox[data-job-id="${jobId}"]`);
        const checkedRoutes = document.querySelectorAll(`.route-checkbox[data-job-id="${jobId}"]:checked`);
        jobCb.checked = allRoutes.length > 0 && allRoutes.length === checkedRoutes.length;
        jobCb.indeterminate = checkedRoutes.length > 0 && checkedRoutes.length < allRoutes.length;
    });
}

function updateSelectAllState() {
    const allCheckboxes = document.querySelectorAll('.route-checkbox');
    const checkedCheckboxes = document.querySelectorAll('.route-checkbox:checked');
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkedCheckboxes.length;
    selectAll.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length;
}

// Clear selection
function clearSelection() {
    document.querySelectorAll('.route-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.job-checkbox').forEach(cb => {
        cb.checked = false;
        cb.indeterminate = false;
    });
    document.getElementById('selectAll').checked = false;
    document.getElementById('selectAll').indeterminate = false;
    updateSelectedCount();
}

// Update count when route checkboxes change
document.querySelectorAll('.route-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        updateSelectedCount();
        updateSelectAllState();
    });
});

// Form validation
document.getElementById('releaseForm').addEventListener('submit', function(e) {
    const checked = document.querySelectorAll('.route-checkbox:checked');
    if (checked.length === 0) {
        e.preventDefault();
        alert('กรุณาเลือก Route อย่างน้อย 1 เส้นทาง');
        return false;
    }
    
    // Check if selected routes have photos
    let missingPhotos = [];
    checked.forEach(cb => {
        const routeId = cb.value;
        const input = document.getElementById('route_photo_' + routeId);
        if (!input.files || input.files.length === 0) {
            const routeCode = cb.closest('tr').querySelector('strong').textContent;
            missingPhotos.push(routeCode);
        }
    });
    
    if (missingPhotos.length > 0) {
        if (!confirm('Route ต่อไปนี้ยังไม่มีรูป:\n' + missingPhotos.join(', ') + '\n\nต้องการดำเนินการต่อหรือไม่?')) {
            e.preventDefault();
            return false;
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
