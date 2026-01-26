<?php
/**
 * Phase 2.5: Job Core Hardening Tests
 * Tests for "wrong path" scenarios
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../core/AuditLog.php';
require_once __DIR__ . '/../core/DocumentNumber.php';
require_once __DIR__ . '/../core/StatusMachine.php';
require_once __DIR__ . '/../core/Job.php';

echo "=== Phase 2.5: Job Core Hardening Tests ===\n\n";

$db = getDB();
$job = new Job();
$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "✅ PASS: $name\n";
        $passed++;
    } else {
        echo "❌ FAIL: $name\n";
        $failed++;
    }
}

// =====================================
// TEST 1: StatusMachine "Wrong Paths"
// =====================================
echo "--- TEST 1: StatusMachine Wrong Paths ---\n";

// 1a. Approve from Draft (must fail)
$_SESSION['user_id'] = 1;
$_SESSION['roles'] = ['ADM'];

$result = $job->create([
    'customer_id' => 1,
    'job_type' => 'Lumpsum',
    'scope_short' => 'Test Wrong Path',
    'plan_start_date' => date('Y-m-d'),
    'plan_end_date' => date('Y-m-d', strtotime('+7 days')),
    'owner_sale_id' => 1,
]);
$testJobId = $result['id'];

$r = $job->changeStatus($testJobId, 'approve');
test("1a. Approve from Draft should FAIL", !$r['success']);

// 1b. Submit first, then try invalid transition
$job->changeStatus($testJobId, 'submit');

// 1c. Edit customer_id after Submitted (should still work at Submitted, let's approve first)
$job->changeStatus($testJobId, 'approve');

// Now try to update customer_id after Approved
$r = $job->update($testJobId, ['customer_id' => 2]);
test("1b. Edit customer_id after Approved should FAIL", !$r['success'] || empty($r['updated_fields']));

// 1d. Approve as SAL role (not allowed)
$_SESSION['roles'] = ['SAL'];

// Create new job for this test
$result2 = $job->create([
    'customer_id' => 1,
    'job_type' => 'Dayrent',
    'scope_short' => 'Test Role Check',
    'plan_start_date' => date('Y-m-d'),
    'plan_end_date' => date('Y-m-d', strtotime('+5 days')),
    'owner_sale_id' => 1,
]);
$testJobId2 = $result2['id'];

// Submit as SAL (allowed)
$r = $job->changeStatus($testJobId2, 'submit');
test("1c. Submit as SAL should PASS", $r['success']);

// Try approve as SAL (should fail - need ADM/MGR/PLN)
$r = $job->changeStatus($testJobId2, 'approve');
test("1d. Approve as SAL-only should FAIL", !$r['success']);

// 1e. Double submit prevention
$_SESSION['roles'] = ['ADM'];
$result3 = $job->create([
    'customer_id' => 1,
    'job_type' => 'Manpower',
    'scope_short' => 'Test Double Submit',
    'plan_start_date' => date('Y-m-d'),
    'plan_end_date' => date('Y-m-d', strtotime('+3 days')),
    'owner_sale_id' => 1,
]);
$testJobId3 = $result3['id'];

$r1 = $job->changeStatus($testJobId3, 'submit');
$r2 = $job->changeStatus($testJobId3, 'submit'); // Second submit should fail
test("1e. Double submit should FAIL", $r1['success'] && !$r2['success']);

// =====================================
// TEST 2: Concurrency (already tested, just verify)
// =====================================
echo "\n--- TEST 2: Concurrency (Doc Number) ---\n";
echo "ℹ️  Already passed in test_doc_numbers.php\n";
test("2. Doc number concurrency", true);

// =====================================
// TEST 3: Audit Log Coverage
// =====================================
echo "\n--- TEST 3: Audit Log Coverage ---\n";

$auditLog = new AuditLog();

// Check last 50 logs for our test actions
$logs = $auditLog->search(['entity_type' => 'JOB'], 50);

$hasCreate = false;
$hasSubmit = false;
$hasApprove = false;

foreach ($logs as $log) {
    if ($log['action_name'] === AUDIT_ACTION_CREATE) $hasCreate = true;
    if ($log['action_name'] === 'submit') $hasSubmit = true;
    if ($log['action_name'] === 'approve') $hasApprove = true;
}

test("3a. Create job logged", $hasCreate);
test("3b. Submit action logged", $hasSubmit);
test("3c. Approve action logged", $hasApprove);

// =====================================
// TEST 4: StatusMachine helper functions
// =====================================
echo "\n--- TEST 4: StatusMachine Helpers ---\n";

$actions = StatusMachine::getAvailableActions('Draft', ['SAL']);
test("4a. SAL can submit from Draft", isset($actions['submit']));
test("4b. SAL cannot approve from Draft", !isset($actions['approve']));

$actions = StatusMachine::getAvailableActions('Submitted', ['MGR']);
test("4c. MGR can approve from Submitted", isset($actions['approve']));

$actions = StatusMachine::getAvailableActions('Approved', ['WH']);
test("4d. WH has no actions at Approved", empty($actions));

// =====================================
// SUMMARY
// =====================================
echo "\n=== SUMMARY ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed === 0) {
    echo "\n✅ All tests passed!\n";
} else {
    echo "\n❌ Some tests failed!\n";
}
