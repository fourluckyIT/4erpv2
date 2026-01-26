<?php
/**
 * Self-Test Pack Runner
 * 
 * Runs all E2E scenario tests and generates a summary report.
 * 
 * Usage: php tests/run_selftests.php
 * Exit 0 = All pass, Exit 1 = Any fail
 */

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║           4ERP v2 SELF-TEST PACK RUNNER                    ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$startTime = microtime(true);
$testDir = __DIR__;

// Define test suite
$tests = [
    // Core E2E (existing)
    ['name' => 'M7 E2E Happy Path', 'file' => 'manual_e2e.php'],
    
    // Scenario tests
    ['name' => 'E2E Dayrent Job', 'file' => 'manual_e2e_dayrent.php'],
    ['name' => 'E2E Lumpsum Job', 'file' => 'manual_e2e_lumpsum.php'],
    ['name' => 'E2E Manpower Job', 'file' => 'manual_e2e_manpower.php'],
    ['name' => 'E2E Device POS Check', 'file' => 'manual_e2e_device_pos.php'],
    ['name' => 'E2E Conflict Matrix', 'file' => 'manual_e2e_conflicts.php'],
    ['name' => 'E2E Reversals/CN', 'file' => 'manual_e2e_reversals.php'],
    
    // Unit tests (existing)
    ['name' => 'M1 Route Conflict', 'file' => 'manual_route_conflict.php'],
    ['name' => 'M2 Procurement', 'file' => 'manual_procurement_flow.php'],
    ['name' => 'M3 Route Execution', 'file' => 'manual_route_execution_m3.php'],
    ['name' => 'M4 Warehouse', 'file' => 'manual_warehouse_return.php'],
    ['name' => 'M5 Accounting AR', 'file' => 'manual_accounting_ar.php'],
    ['name' => 'M6 RBAC Sanity', 'file' => 'manual_rbac_sanity.php'],
];

$results = [];
$totalPassed = 0;
$totalFailed = 0;

foreach ($tests as $idx => $test) {
    $num = $idx + 1;
    $filePath = $testDir . '/' . $test['file'];
    
    echo str_repeat("─", 60) . "\n";
    echo "[$num/" . count($tests) . "] Running: {$test['name']}\n";
    echo "File: {$test['file']}\n";
    echo str_repeat("─", 60) . "\n";
    
    if (!file_exists($filePath)) {
        echo "  [SKIP] File not found\n\n";
        $results[] = [
            'name' => $test['name'],
            'status' => 'SKIP',
            'exit_code' => -1,
            'duration' => 0
        ];
        continue;
    }
    
    $testStart = microtime(true);
    
    // Run test in subprocess
    $envVars = 'TEST_USER_ID=1';
    $command = "$envVars php " . escapeshellarg($filePath) . " 2>&1";
    
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    
    $testEnd = microtime(true);
    $duration = round($testEnd - $testStart, 2);
    
    // Print output (last 10 lines for brevity)
    $outputSlice = array_slice($output, -10);
    echo implode("\n", $outputSlice) . "\n\n";
    
    $status = ($exitCode === 0) ? 'PASS' : 'FAIL';
    
    if ($exitCode === 0) {
        $totalPassed++;
    } else {
        $totalFailed++;
    }
    
    $results[] = [
        'name' => $test['name'],
        'status' => $status,
        'exit_code' => $exitCode,
        'duration' => $duration
    ];
    
    echo "Result: [$status] in {$duration}s\n\n";
}

$endTime = microtime(true);
$totalDuration = round($endTime - $startTime, 2);

// Print summary table
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                    TEST SUMMARY                            ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";

printf("║ %-30s │ %-8s │ %8s ║\n", "Test Name", "Status", "Duration");
echo "╠════════════════════════════════════════════════════════════╣\n";

foreach ($results as $r) {
    $statusIcon = match($r['status']) {
        'PASS' => '✅',
        'FAIL' => '❌',
        'SKIP' => '⏭️',
        default => '?'
    };
    printf("║ %-30s │ %s %-5s │ %6.2fs ║\n", 
           substr($r['name'], 0, 30), 
           $statusIcon, 
           $r['status'], 
           $r['duration']);
}

echo "╠════════════════════════════════════════════════════════════╣\n";
printf("║ TOTAL: %d tests │ ✅ %d PASS │ ❌ %d FAIL │ %7.2fs ║\n",
       count($results), $totalPassed, $totalFailed, $totalDuration);
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Final result
if ($totalFailed > 0) {
    echo "═══ RESULT: FAIL ═══\n";
    echo "One or more tests failed. See output above for details.\n";
    exit(1);
} else {
    echo "═══ RESULT: ALL PASS ═══\n";
    echo "All tests completed successfully.\n";
    exit(0);
}
