<?php
/**
 * People Management
 * ERP v2 - HR Module
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

$pageTitle = 'People Management - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-people me-2"></i>People Management</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Home</a></li>
                    <li class="breadcrumb-item active">People</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มบุคลากร
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['total'] ?></h4>
                        <small>ทั้งหมด</small>
                    </div>
                    <i class="bi bi-people fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['internal'] ?></h4>
                        <small>พนักงานประจำ</small>
                    </div>
                    <i class="bi bi-person-badge fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['external'] ?></h4>
                        <small>แรงงานภายนอก</small>
                    </div>
                    <i class="bi bi-person-workspace fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['active'] ?></h4>
                        <small>พร้อมใช้งาน</small>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <a href="?filter=all" class="btn btn-<?= $filter === 'all' ? 'primary' : 'outline-primary' ?> btn-sm">ทั้งหมด</a>
                    <a href="?filter=internal" class="btn btn-<?= $filter === 'internal' ? 'success' : 'outline-success' ?> btn-sm">พนักงานประจำ</a>
                    <a href="?filter=external" class="btn btn-<?= $filter === 'external' ? 'warning' : 'outline-warning' ?> btn-sm">แรงงานภายนอก</a>
                    <a href="?filter=active" class="btn btn-<?= $filter === 'active' ? 'info' : 'outline-info' ?> btn-sm">Active</a>
                </div>
            </div>
            <div class="col-auto flex-grow-1">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="ค้นหาชื่อ, รหัส, บัตร ปชช., โทร...">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- People List -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>รหัส</th>
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
                <tr><td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                <?php foreach ($people as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td>
                        <strong><?= e($p['full_name']) ?></strong>
                        <?php if ($p['id_card']): ?>
                        <br><small class="text-muted"><?= e($p['id_card']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $p['people_type'] === 'Employee' ? 'success' : 'warning text-dark' ?>">
                            <?= $p['people_type'] === 'Employee' ? 'พนักงานประจำ' : 'แรงงานภายนอก' ?>
                        </span>
                    </td>
                    <td><?= e($p['position'] ?: '-') ?></td>
                    <td><?= e($p['phone'] ?: '-') ?></td>
                    <td><?= e($p['supplier_name'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
