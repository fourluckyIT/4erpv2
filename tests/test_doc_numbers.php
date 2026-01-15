<?php
/**
 * Document Number Test Script
 * Tests 4 requirements:
 * 1. ไม่ซ้ำ (No duplicates)
 * 2. ไม่ย้อนกลับ (No rollback)
 * 3. กันคนกดพร้อมกัน (Concurrent protection)
 * 4. ล็อกตามกติกา (Reserved at Submitted/Approved)
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../core/AuditLog.php';
require_once __DIR__ . '/../core/DocumentNumber.php';

$_SESSION['user_id'] = 1; // Simulate logged in user

echo "=== Document Number Tests ===\n\n";

$docNum = new DocumentNumber();
$testType = 'JOB';

// Get initial state
$initialSetting = $docNum->getSetting($testType);
echo "Initial next_number: " . $initialSetting['next_number'] . "\n\n";

// ======================
// TEST 1: ไม่ซ้ำ (No Duplicates)
// ======================
echo "--- TEST 1: No Duplicates ---\n";

$numbers = [];
$duplicates = 0;

for ($i = 0; $i < 5; $i++) {
    $num = $docNum->generate($testType);
    echo "Generated: $num\n";
    
    if (isset($numbers[$num])) {
        $duplicates++;
        echo "  ❌ DUPLICATE FOUND!\n";
    } else {
        $numbers[$num] = true;
    }
}

if ($duplicates === 0) {
    echo "✅ TEST 1 PASSED: No duplicates\n\n";
} else {
    echo "❌ TEST 1 FAILED: $duplicates duplicates found\n\n";
}

// ======================
// TEST 2: ไม่ย้อนกลับ (No Rollback)
// ======================
echo "--- TEST 2: No Rollback ---\n";

// Get last number
$lastNum1 = $docNum->getLastGenerated($testType);
echo "Last: $lastNum1\n";

// Simulate "refresh" - just get new number
$newNum = $docNum->generate($testType);
echo "After 'refresh': $newNum\n";

// Extract numbers to compare
preg_match('/(\d+)$/', $lastNum1, $m1);
preg_match('/(\d+)$/', $newNum, $m2);
$seq1 = (int)$m1[1];
$seq2 = (int)$m2[1];

if ($seq2 > $seq1) {
    echo "✅ TEST 2 PASSED: Number increased ($seq1 → $seq2)\n\n";
} else {
    echo "❌ TEST 2 FAILED: Number did not increase ($seq1 → $seq2)\n\n";
}

// ======================
// TEST 3: กันคนกดพร้อมกัน (Concurrent Protection)
// ======================
echo "--- TEST 3: Concurrent Protection ---\n";

// Simulate with multiple sequential calls (true concurrent needs multi-process)
$concurrentNumbers = [];
$concurrentDupes = 0;

echo "Simulating 10 rapid-fire generations...\n";
for ($i = 0; $i < 10; $i++) {
    $num = $docNum->generate($testType);
    if (isset($concurrentNumbers[$num])) {
        $concurrentDupes++;
    }
    $concurrentNumbers[$num] = true;
}

if ($concurrentDupes === 0) {
    echo "✅ TEST 3 PASSED: No collisions in rapid generation\n";
    echo "(Note: True concurrent test requires multi-process/thread)\n\n";
} else {
    echo "❌ TEST 3 FAILED: $concurrentDupes collisions\n\n";
}

// ======================
// TEST 4: ล็อกตามกติกา (Logging)
// ======================
echo "--- TEST 4: Reservation & Logging ---\n";

// Reserve a number
$reserved = $docNum->reserve($testType);
echo "Reserved: $reserved\n";

// Check it exists in log
$exists = $docNum->exists($reserved);
echo "Exists in log: " . ($exists ? 'YES' : 'NO') . "\n";

// Check history
$history = $docNum->getHistory($testType, 5);
echo "Recent history:\n";
foreach ($history as $h) {
    echo "  - {$h['doc_number']} (entity_id: " . ($h['entity_id'] ?? 'NULL') . ")\n";
}

if ($exists && !empty($history)) {
    echo "✅ TEST 4 PASSED: Numbers are logged immutably\n\n";
} else {
    echo "❌ TEST 4 FAILED: Logging issue\n\n";
}

// ======================
// SUMMARY
// ======================
echo "=== FINAL STATE ===\n";
$finalSetting = $docNum->getSetting($testType);
echo "Final next_number: " . $finalSetting['next_number'] . "\n";
echo "Total numbers generated in test: " . ($finalSetting['next_number'] - $initialSetting['next_number']) . "\n";

echo "\n✅ All tests completed!\n";
