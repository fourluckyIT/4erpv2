<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function q(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo "=== REQUIREMENTS REPORT ===\n\n";

// 1) Compliance requirements by site
$sites = q($pdo, "SELECT id, name FROM sites ORDER BY id");
$reqCount = (int)$pdo->query("SELECT COUNT(*) FROM compliance_requirements")->fetchColumn();
echo "Compliance requirements total: {$reqCount}\n\n";

foreach ($sites as $s) {
    $reqs = q($pdo, "
        SELECT id, requirement_type, name, is_mandatory, applies_to, is_active
        FROM compliance_requirements
        WHERE site_id = ?
        ORDER BY is_mandatory DESC, requirement_type, name
    ", [$s['id']]);

    echo "[Site #{$s['id']}] {$s['name']}\n";
    if (!$reqs) {
        echo "  - (none)\n";
        continue;
    }

    foreach ($reqs as $r) {
        echo "  - #{$r['id']} type={$r['requirement_type']} name={$r['name']} mandatory={$r['is_mandatory']} applies_to={$r['applies_to']} active={$r['is_active']}\n";
    }
}

echo "\n";

// 2) Job required certs (if used)
$hasJobReqTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'job_required_certs'")->fetchColumn();
if ($hasJobReqTable) {
    $jobReqs = q($pdo, "
        SELECT j.id as job_id, j.job_number, jrc.cert_name, jrc.is_mandatory
        FROM job_required_certs jrc
        JOIN jobs j ON jrc.job_id = j.id
        ORDER BY j.id DESC, jrc.id DESC
        LIMIT 200
    ");

    echo "Job required certs (latest 200): " . count($jobReqs) . "\n";
    if (!$jobReqs) {
        echo "- (none)\n";
    } else {
        foreach ($jobReqs as $jr) {
            echo "- Job#{$jr['job_id']} {$jr['job_number']} requires {$jr['cert_name']} mandatory={$jr['is_mandatory']}\n";
        }
    }
} else {
    echo "job_required_certs table not found (skip)\n";
}

echo "\n";

// 3) Evidence photos requirements (static per agents.md)
$evidence = [
    'Dispatch' => 4,
    'Receive' => 4,
    'Return' => 4,
    'POS Check (Device only)' => 4,
];

echo "Evidence photo requirements:\n";
foreach ($evidence as $k => $v) {
    echo "- {$k}: {$v} photos per route\n";
}

echo "\n";

// 4) Certificate inventory counts
$peopleCerts = (int)$pdo->query("SELECT COUNT(*) FROM people_certificates")->fetchColumn();
$serialCerts = (int)$pdo->query("SELECT COUNT(*) FROM serial_certificates")->fetchColumn();

echo "Certificates stored:\n";
echo "- people_certificates: {$peopleCerts}\n";
echo "- serial_certificates: {$serialCerts}\n";

echo "\n";

// 5) System settings for certificate types
$settings = q($pdo, "SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'compliance' ORDER BY setting_key");

echo "Compliance settings:\n";
if (!$settings) {
    echo "- (none)\n";
} else {
    foreach ($settings as $st) {
        echo "- {$st['setting_key']}: {$st['setting_value']}\n";
    }
}

echo "\n=== END REPORT ===\n";
