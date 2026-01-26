<?php
/**
 * M6: RBAC Sanity Test - Manual Verification Script
 * 
 * Tests (per agents.md §4):
 * 1. WH cannot issue invoice (DENY)
 * 2. ACC cannot WH receive (DENY)
 * 3. PLN can dispatch route (ALLOW)
 * 4. PUR can create PR/PO/GR (ALLOW)
 * 5. MGR can approve/override (ALLOW)
 * 6. ADM can do all (ALLOW)
 * 7. Unknown action denied (DENY)
 * 
 * Run: php tests/manual_rbac_sanity.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';

echo "=== M6: RBAC Sanity Test ===\n\n";

$passed = 0;
$failed = 0;

/**
 * Test helper
 */
function testPermission(string $role, string $action, bool $expected, string $desc): void {
    global $passed, $failed;
    
    $result = Policy::roleCanDo($role, $action);
    $status = $result === $expected ? 'PASS' : 'FAIL';
    $expectStr = $expected ? 'ALLOW' : 'DENY';
    $actualStr = $result ? 'ALLOW' : 'DENY';
    
    if ($result === $expected) {
        echo "[PASS] $desc\n";
        echo "  Role: $role, Action: $action, Expected: $expectStr\n\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        echo "  Role: $role, Action: $action, Expected: $expectStr, Got: $actualStr\n\n";
        $failed++;
    }
}

// ======= TEST 1: WH cannot issue invoice =======
echo "[Test 1] WH cannot issue invoice (DENY expected)\n";
testPermission(Policy::ROLE_WH, Policy::INVOICE_ISSUE, false, 'WH cannot INVOICE_ISSUE');

// ======= TEST 2: WH cannot create invoice =======
echo "[Test 2] WH cannot create invoice (DENY expected)\n";
testPermission(Policy::ROLE_WH, Policy::INVOICE_CREATE, false, 'WH cannot INVOICE_CREATE');

// ======= TEST 3: ACC cannot WH receive =======
echo "[Test 3] ACC cannot WH receive (DENY expected)\n";
testPermission(Policy::ROLE_ACC, Policy::WH_RECEIVE_RETURN, false, 'ACC cannot WH_RECEIVE_RETURN');

// ======= TEST 4: ACC cannot stock move =======
echo "[Test 4] ACC cannot stock move (DENY expected)\n";
testPermission(Policy::ROLE_ACC, Policy::STOCK_MOVE_RECORD, false, 'ACC cannot STOCK_MOVE_RECORD');

// ======= TEST 5: PLN can dispatch route =======
echo "[Test 5] PLN can dispatch route (ALLOW expected)\n";
testPermission(Policy::ROLE_PLN, Policy::ROUTE_DISPATCH, true, 'PLN can ROUTE_DISPATCH');

// ======= TEST 6: PLN can plan job =======
echo "[Test 6] PLN can plan job (ALLOW expected)\n";
testPermission(Policy::ROLE_PLN, Policy::JOB_PLAN, true, 'PLN can JOB_PLAN');

// ======= TEST 7: PUR can create PR =======
echo "[Test 7] PUR can create PR (ALLOW expected)\n";
testPermission(Policy::ROLE_PUR, Policy::PR_CREATE, true, 'PUR can PR_CREATE');

// ======= TEST 8: PUR can create PO =======
echo "[Test 8] PUR can create PO (ALLOW expected)\n";
testPermission(Policy::ROLE_PUR, Policy::PO_CREATE, true, 'PUR can PO_CREATE');

// ======= TEST 9: WH can create GR =======
echo "[Test 9] WH can create GR (ALLOW expected)\n";
testPermission(Policy::ROLE_WH, Policy::GR_CREATE, true, 'WH can GR_CREATE');

// ======= TEST 10: MGR can approve job =======
echo "[Test 10] MGR can approve job (ALLOW expected)\n";
testPermission(Policy::ROLE_MGR, Policy::JOB_APPROVE, true, 'MGR can JOB_APPROVE');

// ======= TEST 11: MGR can void job =======
echo "[Test 11] MGR can void job (ALLOW expected)\n";
testPermission(Policy::ROLE_MGR, Policy::JOB_VOID, true, 'MGR can JOB_VOID');

// ======= TEST 12: MGR can approve stock adjust =======
echo "[Test 12] MGR can approve stock adjust (ALLOW expected)\n";
testPermission(Policy::ROLE_MGR, Policy::STOCK_ADJUST_APPROVE, true, 'MGR can STOCK_ADJUST_APPROVE');

// ======= TEST 13: ACC can create invoice =======
echo "[Test 13] ACC can create invoice (ALLOW expected)\n";
testPermission(Policy::ROLE_ACC, Policy::INVOICE_CREATE, true, 'ACC can INVOICE_CREATE');

// ======= TEST 14: ACC can record payment =======
echo "[Test 14] ACC can record payment (ALLOW expected)\n";
testPermission(Policy::ROLE_ACC, Policy::PAYMENT_RECORD, true, 'ACC can PAYMENT_RECORD');

// ======= TEST 15: ADM can do invoice =======
echo "[Test 15] ADM can do invoice (ALLOW expected)\n";
testPermission(Policy::ROLE_ADM, Policy::INVOICE_ISSUE, true, 'ADM can INVOICE_ISSUE');

// ======= TEST 16: ADM can do WH receive =======
echo "[Test 16] ADM can do WH receive (ALLOW expected)\n";
testPermission(Policy::ROLE_ADM, Policy::WH_RECEIVE_RETURN, true, 'ADM can WH_RECEIVE_RETURN');

// ======= TEST 17: ADM can override cert =======
echo "[Test 17] ADM can override cert (ALLOW expected)\n";
testPermission(Policy::ROLE_ADM, Policy::PLAN_OVERRIDE_CERT, true, 'ADM can PLAN_OVERRIDE_CERT');

// ======= TEST 18: SAL cannot approve job =======
echo "[Test 18] SAL cannot approve job (DENY expected)\n";
testPermission(Policy::ROLE_SAL, Policy::JOB_APPROVE, false, 'SAL cannot JOB_APPROVE');

// ======= TEST 19: HR can register manpower =======
echo "[Test 19] HR can register manpower (ALLOW expected)\n";
testPermission(Policy::ROLE_HR, Policy::GR_REGISTER_MANPOWER, true, 'HR can GR_REGISTER_MANPOWER');

// ======= TEST 20: Unknown action denied =======
echo "[Test 20] Unknown action denied (DENY expected)\n";
$unknownResult = Policy::roleCanDo(Policy::ROLE_ACC, 'FAKE_ACTION');
if ($unknownResult === false) {
    echo "[PASS] Unknown action correctly denied.\n\n";
    $passed++;
} else {
    echo "[FAIL] Unknown action should be denied.\n\n";
    $failed++;
}

// ======= TEST 21: Policy class instance test =======
echo "[Test 21] Policy class with session roles test...\n";
$_SESSION['user_id'] = 1;
$_SESSION['roles'] = ['WH'];
$policy = new Policy();
$policy->setUserRoles(['WH']);

if (!$policy->can(Policy::INVOICE_CREATE)) {
    echo "[PASS] Policy instance correctly denies WH from INVOICE_CREATE.\n\n";
    $passed++;
} else {
    echo "[FAIL] Policy instance should deny WH from INVOICE_CREATE.\n\n";
    $failed++;
}

// ======= TEST 22: Policy require throws exception =======
echo "[Test 22] Policy require throws exception on deny...\n";
$policy->setUserRoles(['SAL']);
try {
    $policy->require(Policy::JOB_APPROVE);
    echo "[FAIL] Should have thrown exception.\n\n";
    $failed++;
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Permission denied') !== false) {
        echo "[PASS] Correctly threw 'Permission denied' exception.\n\n";
        $passed++;
    } else {
        echo "[FAIL] Wrong exception message: {$e->getMessage()}\n\n";
        $failed++;
    }
}

// ======= SUMMARY =======
echo "=== M6 RBAC Sanity Test Complete ===\n";
echo "Passed: $passed, Failed: $failed\n";

if ($failed > 0) {
    echo "RESULT: FAIL\n";
    exit(1);
} else {
    echo "RESULT: PASS\n";
    exit(0);
}
