<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $pdo = getDBConnection();
    // First, get all approved applications
    $stmt = $pdo->prepare("
        SELECT a.id, a.sr_code, a.first_name, a.middle_name, a.last_name, a.full_name, a.email, a.application_status, 
               a.approved_at, a.reviewed_by,
               COALESCE(CONCAT(reviewer.first_name, ' ', reviewer.last_name), 'Unknown') as reviewer_name
        FROM applications a
        LEFT JOIN users reviewer ON a.reviewed_by = reviewer.id
        WHERE a.application_status = 'approved'
        ORDER BY a.approved_at DESC
    ");
    $stmt->execute();
    $allApproved = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $applications = [];
    
    // Filter out applications that already have accounts or were deleted
    foreach ($allApproved as $app) {
        $email = $app['email'];
        $sr_code = $app['sr_code'];
        
        // Check if user exists in users table
        $userCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $userCheck->execute([$email]);
        $userExists = (bool)$userCheck->fetchColumn();
        
        // Check if user exists in student_artists table
        $artistCheck = $pdo->prepare("SELECT id FROM student_artists WHERE email = ? OR sr_code = ?");
        $artistCheck->execute([$email, $sr_code]);
        $artistExists = (bool)$artistCheck->fetchColumn();
        
        // Check if user was deleted by admin
        $deletedCheck = $pdo->prepare("SELECT id FROM deleted_students WHERE email = ? OR sr_code = ?");
        $deletedCheck->execute([$email, $sr_code]);
        $wasDeleted = (bool)$deletedCheck->fetchColumn();
        
        // If no account exists in any table and was not deleted, add to results
        if (!$userExists && !$artistExists && !$wasDeleted) {
            $applications[] = $app;
        }
    }

    // Format the applications data
    foreach ($applications as &$app) {
        // Handle null approved_at
        if ($app['approved_at']) {
            $app['approved_at'] = date('M d, Y H:i', strtotime($app['approved_at']));
        } else {
            $app['approved_at'] = 'Recently approved';
        }
        
        // Use the separate name fields from database if available, fallback to parsing full_name
        if (empty($app['first_name']) && !empty($app['full_name'])) {
            // Fallback: Split full name into first and last name for old records
            $nameParts = explode(' ', trim($app['full_name']));
            if (count($nameParts) >= 2) {
                $app['last_name'] = array_pop($nameParts); // Get last word as last name
                $app['first_name'] = implode(' ', $nameParts); // Everything else as first name
            } else {
                $app['first_name'] = $app['full_name'];
                $app['last_name'] = '';
            }
        }
        // Ensure we have values even if fields are null
        $app['first_name'] = $app['first_name'] ?: '';
        $app['middle_name'] = $app['middle_name'] ?: '';
        $app['last_name'] = $app['last_name'] ?: '';
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'applications' => $applications,
        'count' => count($applications)
    ]);

} catch (Exception $e) {
    error_log("Error fetching approved applications: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch approved applications: ' . $e->getMessage()]);
}
?>