<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

$timesheetId = 1;

// Get timesheet info
$stmt = $db->query("SELECT * FROM timesheets WHERE id = $timesheetId");
$ts = $stmt->fetch();
if (!$ts) {
    die("Timesheet not found\n");
}

$jobId = $ts['job_id'];
echo "Populating timesheet #$timesheetId for job #$jobId\n";

// Get people from confirmed plans
$stmt = $db->prepare("
    SELECT DISTINCT pa.id as assignment_id, pa.people_id, p.daily_rate, p.full_name
    FROM plan_assignments pa
    JOIN plans pl ON pa.plan_id = pl.id
    JOIN people p ON pa.people_id = p.id
    WHERE pl.job_id = ?
      AND pl.status = 'Confirmed'
      AND pa.people_id IS NOT NULL
      AND p.is_active = 1
");
$stmt->execute([$jobId]);
$assignments = $stmt->fetchAll();

echo "Found " . count($assignments) . " people from confirmed plans\n";

$count = 0;
foreach ($assignments as $a) {
    // Check if already exists
    $check = $db->prepare("SELECT id FROM timesheet_entries WHERE timesheet_id = ? AND people_id = ?");
    $check->execute([$timesheetId, $a['people_id']]);
    if ($check->fetch()) {
        echo "Skipping {$a['full_name']} - already exists\n";
        continue;
    }
    
    $stmt = $db->prepare("
        INSERT INTO timesheet_entries (
            timesheet_id, people_id, is_present, daily_rate, 
            from_plan_assignment_id, manually_added
        ) VALUES (?, ?, 1, ?, ?, 0)
    ");
    $stmt->execute([
        $timesheetId,
        $a['people_id'],
        $a['daily_rate'] ?? 0,
        $a['assignment_id']
    ]);
    echo "Added: {$a['full_name']}\n";
    $count++;
}

// Update total_workers
$stmt = $db->prepare("UPDATE timesheets SET total_workers = (SELECT COUNT(*) FROM timesheet_entries WHERE timesheet_id = ?) WHERE id = ?");
$stmt->execute([$timesheetId, $timesheetId]);

echo "\nDone! Added $count people to timesheet\n";
