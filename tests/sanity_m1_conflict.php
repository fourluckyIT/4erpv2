<?php
/**
 * Sanity Test for M1 Conflict Prevention
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Plan.php';

// Mock session
$_SESSION['user_id'] = 1;

$db = getDB();
$planModel = new Plan();

echo "Running M1 Conflict Sanity Test...\n";

try {
    // 1. Setup: Ensure we have a Job and Serial
    // Get first approved job
    $stmt = $db->query("SELECT id FROM jobs WHERE status = 'Approved' LIMIT 1");
    $jobId = $stmt->fetchColumn();
    
    if (!$jobId) {
        die("Error: No Approved Job found. Please create one manually first.\n");
    }
    
    // Get first available serial
    $stmt = $db->query("SELECT id FROM serials WHERE status = 'Available' LIMIT 1");
    $serialId = $stmt->fetchColumn();
    
    if (!$serialId) {
        die("Error: No Available Serial found.\n");
    }
    
    // 2. Create Plan A
    echo "Creating Plan A for Job $jobId...\n";
    $planA = $planModel->create($jobId, ['plan_date' => date('Y-m-d'), 'notes' => 'Test Plan A']);
    if (!$planA['success']) {
        // May fail if plan already exists. Let's find active plan or try another job.
        // For simplicity, we assume we can create one or we clean up.
        // Let's try to delete existing plans for this job for testing? No, too dangerous.
        die("Error creating Plan A: " . $planA['error'] . "\n");
    }
    $planAId = $planA['id'];
    echo "Plan A created: ID $planAId\n";
    
    // 3. Book Serial on Plan A
    echo "Booking Serial $serialId on Plan A...\n";
    $resA = $planModel->addSerial($planAId, $serialId);
    if (!$resA['success']) {
        die("Error booking on Plan A: " . $resA['error'] . "\n");
    }
    echo "Success: Booked on Plan A.\n";
    
    // 4. Create Plan B (Same Job? No, same job can't have 2 plans. Need another job.)
    // But conflict is PER RESOURCE, across ANY plan.
    // So let's find ANOTHER Approved Job.
    $stmt = $db->prepare("SELECT id FROM jobs WHERE status = 'Approved' AND id != ? LIMIT 1");
    $stmt->execute([$jobId]);
    $jobId2 = $stmt->fetchColumn();
    
    if (!$jobId2) {
        echo "Warning: Only 1 job available. Cannot test cross-job conflict fully. Creating a dummy job...\n";
        // Create dummy job logic or skip
        $db->exec("INSERT INTO jobs (job_number, customer_id, status, plan_start_date, plan_end_date, created_by) 
                   VALUES ('TEST-JOB-999', 1, 'Approved', CURDATE(), CURDATE(), 1)");
        $jobId2 = $db->lastInsertId();
    }
    
    echo "Creating Plan B for Job $jobId2...\n";
    $planB = $planModel->create($jobId2, ['plan_date' => date('Y-m-d'), 'notes' => 'Test Plan B']);
    if (!$planB['success']) {
        die("Error creating Plan B: " . $planB['error'] . "\n");
    }
    $planBId = $planB['id'];
    echo "Plan B created: ID $planBId\n";
    
    // 5. Try to Book SAME Serial on Plan B (Should Fail)
    echo "Attempting to book Serial $serialId on Plan B (Should Fail)...\n";
    $resB = $planModel->addSerial($planBId, $serialId);
    
    if ($resB['success']) {
        echo "BSOD! FAILURE! Booked conflicted resource!\n";
        exit(1);
    } else {
        echo "Success: Blocked correctly with error: " . $resB['error'] . "\n";
    }
    
    // 6. Cancel Plan A
    echo "Cancelling Plan A...\n";
    $resCancel = $planModel->cancel($planAId, 'Testing release');
    if (!$resCancel['success']) {
        die("Error cancelling Plan A: " . $resCancel['error'] . "\n");
    }
    
    // 7. Retry Booking on Plan B (Should SUCCEED now)
    echo "Retrying booking Serial $serialId on Plan B (Should SUCCEED)...\n";
    $resB2 = $planModel->addSerial($planBId, $serialId);
    
    if (!$resB2['success']) {
        echo "Failure! Should be free now but failed: " . $resB2['error'] . "\n";
        exit(1);
    }
    echo "Success: Booked on Plan B after release.\n";
    
    // Build Clean up
    $planModel->cancel($planBId, 'Cleanup test');
    echo "Test Complete.\n";

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
