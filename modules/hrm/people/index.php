<?php
/**
 * People Management
 * 4ERP - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get filter
$filter = get('filter', 'all');
$search = get('search', '');

// Build query
$sql = "
    SELECT p.*, 
           s.name as supplier_name,
           (SELECT COUNT(*) FROM po_manpower pm WHERE pm.people_id = p.id) as po_count
    FROM people p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE 1=1
";
$params = [];

if ($filter === 'internal') {
    $sql .= " AND p.people_type = 'Employee'";
} elseif ($filter === 'external') {
    $sql .= " AND p.people_type = 'External'";
} elseif ($filter === 'active') {
    $sql .= " AND p.is_active = 1";
} elseif ($filter === 'inactive') {
    $sql .= " AND p.is_active = 0";
}

if ($search) {
    $sql .= " AND (p.full_name LIKE ? OR p.code LIKE ? OR p.id_card LIKE ? OR p.phone LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

$sql .= " ORDER BY p.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$people = $stmt->fetchAll();

// Stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN people_type = 'Employee' THEN 1 ELSE 0 END) as internal,
        SUM(CASE WHEN people_type = 'External' THEN 1 ELSE 0 END) as external,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
    FROM people
")->fetch();

$pageTitle = 'People Management - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-people" style="color: var(--primary);"></i> People
        </h1>
        <p class="page-subtitle">รายการบุคลากรและแรงงานทั้งหมด</p>
    </div>
    <a href="create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> เพิ่มบุคลากร
    </a>
</div>

<!-- Stats Cards -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-people" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) ($stats['total'] ?? 0) ?></div>
            <div class="stat-label">ทั้งหมด</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-person-badge" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) ($stats['internal'] ?? 0) ?></div>
            <div class="stat-label">พนักงานประจำ</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-person-workspace" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) ($stats['external'] ?? 0) ?></div>
            <div class="stat-label">แรงงานภายนอก</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-check-circle" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) ($stats['active'] ?? 0) ?></div>
            <div class="stat-label">พร้อมใช้งาน</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-4">
                <label class="form-label">ตัวกรอง</label>
                <select class="form-select" name="filter">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                    <option value="internal" <?= $filter === 'internal' ? 'selected' : '' ?>>พนักงานประจำ</option>
                    <option value="external" <?= $filter === 'external' ? 'selected' : '' ?>>แรงงานภายนอก</option>
                    <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-lg-6 col-md-8">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="ค้นหาชื่อ, รหัส, บัตร ปชช., โทร...">
            </div>
            <div class="col-lg-3 col-md-12 d-flex flex-wrap gap-2 justify-content-lg-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> ค้นหา
                </button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<!-- People List -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th class="text-nowrap">รหัส</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ประเภท</th>
                    <th>ตำแหน่ง</th>
                    <th>โทรศัพท์</th>
                    <th>ผู้ขาย/บริษัท</th>
                    <th>สถานะ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($people)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <div class="mt-2">ไม่พบข้อมูล</div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($people as $p): ?>
                <tr>
                    <td class="text-nowrap">
                        <span class="badge bg-light text-dark border"><?= e($p['code']) ?></span>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($p['full_name']) ?></div>
                        <?php if (!empty($p['id_card'])): ?>
                        <small class="text-muted"><?= e($p['id_card']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $p['people_type'] === 'Employee' ? 'success' : 'warning text-dark' ?>">
                            <?= $p['people_type'] === 'Employee' ? 'พนักงานประจำ' : 'แรงงานภายนอก' ?>
                        </span>
                    </td>
                    <td><?= e($p['position'] ?: '-') ?></td>
                    <td class="text-nowrap"><?= e($p['phone'] ?: '-') ?></td>
                    <td><?= e($p['supplier_name'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
