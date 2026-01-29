<?php
/**
 * Job API Endpoints
 * 4ERP - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$action = get('action');

function generateCustomerCode(PDO $db): string {
    $stmt = $db->query("SELECT code FROM customers WHERE code LIKE 'CUST%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/^CUST(\d+)$/', $last, $m)) {
        $n = (int)$m[1] + 1;
        return 'CUST' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }
    return 'CUST' . date('ymdHis');
}

function columnExists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $col = $db->quote($column);
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE {$col}");
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

switch ($action) {
    case 'get_sites':
        $customerId = (int) get('customer_id');
        if (!$customerId) {
            echo json_encode([]);
            exit;
        }
        
        $stmt = $db->prepare("SELECT id, name FROM sites WHERE customer_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$customerId]);
        echo json_encode($stmt->fetchAll());
        break;
        
    case 'get_customers':
        $stmt = $db->query("SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name");
        echo json_encode($stmt->fetchAll());
        break;

    case 'create_customer':
        if (!isPost()) {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        if (!verifyCsrf(post('csrf_token', ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid request']);
            exit;
        }

        $rbac = new RBAC();
        if (!$rbac->can('create', 'JOB')) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }

        $name = sanitize((string)post('name', ''));
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Customer name is required']);
            exit;
        }

        $code = sanitize((string)post('code', ''));
        if ($code === '') {
            $code = generateCustomerCode($db);
        }

        $data = [
            'code' => $code,
            'name' => $name,
            'contact_name' => sanitize((string)post('contact_name', '')) ?: null,
            'phone' => sanitize((string)post('phone', '')) ?: null,
            'email' => sanitize((string)post('email', '')) ?: null,
            'address' => sanitize((string)post('address', '')) ?: null,
            'tax_id' => sanitize((string)post('tax_id', '')) ?: null,
            'created_by' => $_SESSION['user_id'] ?? null,
        ];

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO customers (code, name, contact_name, phone, email, address, tax_id, is_active, created_by) VALUES (:code,:name,:contact_name,:phone,:email,:address,:tax_id,1,:created_by)");
            $stmt->execute($data);
            $customerId = (int)$db->lastInsertId();

            $audit = new AuditLog();
            $audit->log(AUDIT_ACTION_CREATE, 'CUSTOMER', $customerId, null, ['code' => $code, 'name' => $name]);

            // Create sites if provided
            $sitesCreated = 0;
            $sitesJson = post('sites', '');
            if ($sitesJson) {
                $sites = json_decode($sitesJson, true);
                if (is_array($sites)) {
                    $hasMapUrl = columnExists($db, 'sites', 'map_url');
                    if ($hasMapUrl) {
                        $siteStmt = $db->prepare("INSERT INTO sites (customer_id, name, map_url, is_active) VALUES (:customer_id, :name, :map_url, 1)");
                    } else {
                        $siteStmt = $db->prepare("INSERT INTO sites (customer_id, name, is_active) VALUES (:customer_id, :name, 1)");
                    }
                    foreach ($sites as $site) {
                        $siteName = trim($site['name'] ?? '');
                        if ($siteName) {
                            if ($hasMapUrl) {
                                $siteStmt->execute([
                                    'customer_id' => $customerId,
                                    'name' => $siteName,
                                    'map_url' => trim($site['map_url'] ?? '') ?: null
                                ]);
                            } else {
                                $siteStmt->execute([
                                    'customer_id' => $customerId,
                                    'name' => $siteName
                                ]);
                            }
                            $siteId = (int)$db->lastInsertId();
                            $audit->log(AUDIT_ACTION_CREATE, 'SITE', $siteId, null, ['customer_id' => $customerId, 'name' => $siteName]);
                            $sitesCreated++;
                        }
                    }
                }
            }

            $db->commit();

            echo json_encode(['success' => true, 'customer' => ['id' => $customerId, 'code' => $code, 'name' => $name], 'sites_created' => $sitesCreated]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'create_site':
        if (!isPost()) {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        if (!verifyCsrf(post('csrf_token', ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid request']);
            exit;
        }

        $rbac = new RBAC();
        if (!$rbac->can('create', 'JOB')) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }

        $customerId = (int)post('customer_id', 0);
        if (!$customerId) {
            http_response_code(422);
            echo json_encode(['error' => 'Customer ID is required']);
            exit;
        }

        // Verify customer exists
        $stmt = $db->prepare("SELECT id FROM customers WHERE id = ? AND is_active = 1");
        $stmt->execute([$customerId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            exit;
        }

        $siteName = sanitize((string)post('name', ''));
        if ($siteName === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Site name is required']);
            exit;
        }

        try {
            $siteData = [
                'customer_id' => $customerId,
                'name' => $siteName,
                'address' => sanitize((string)post('address', '')) ?: null,
                'map_url' => sanitize((string)post('map_url', '')) ?: null,
            ];
            $hasMapUrl = columnExists($db, 'sites', 'map_url');
            if ($hasMapUrl) {
                $stmt = $db->prepare("INSERT INTO sites (customer_id, name, address, map_url, is_active) VALUES (:customer_id, :name, :address, :map_url, 1)");
                $stmt->execute($siteData);
            } else {
                $stmt = $db->prepare("INSERT INTO sites (customer_id, name, address, is_active) VALUES (:customer_id, :name, :address, 1)");
                $stmt->execute([
                    'customer_id' => $siteData['customer_id'],
                    'name' => $siteData['name'],
                    'address' => $siteData['address'],
                ]);
            }
            $siteId = (int)$db->lastInsertId();

            $audit = new AuditLog();
            $audit->log(AUDIT_ACTION_CREATE, 'SITE', $siteId, null, ['customer_id' => $customerId, 'name' => $siteName]);

            echo json_encode(['success' => true, 'site' => ['id' => $siteId, 'name' => $siteName]]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
