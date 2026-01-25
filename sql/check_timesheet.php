<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

echo "=== Timesheet #1 ===\n";
$stmt = $db->query("SELECT * FROM timesheets WHERE id = 1");
$ts = $stmt->fetch();
print_r($ts);

echo "\n=== Entries for Timesheet #1 ===\n";
$stmt = $db->query("SELECT * FROM timesheet_entries WHERE timesheet_id = 1");
$entries = $stmt->fetchAll();
echo "Count: " . count($entries) . "\n";
print_r($entries);

echo "\n=== Plan Assignments for Job ===\n";
if ($ts) {
    $stmt = $db->prepare("SELECT pa.*, p.full_name, pl.status as plan_status 
                          FROM plan_assignments pa 
                          JOIN plans pl ON pa.plan_id = pl.id 
                          LEFT JOIN people p ON pa.people_id = p.id
                          WHERE pl.job_id = ?");
    $stmt->execute([$ts['job_id']]);
    $assignments = $stmt->fetchAll();
    echo "Count: " . count($assignments) . "\n";
    print_r($assignments);
}
