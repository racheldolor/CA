<?php
// Prevent any output before JSON
ob_start();

// Set content type to JSON and turn off error display
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

session_start();

// Check if user is logged in and is admin (head or staff)
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'];
$user_email = $_SESSION['user_email'];
$user_campus_raw = $_SESSION['user_campus'] ?? null;

// Campus name normalization
$campus_name_map = [
    'Malvar' => 'JPLPC Malvar',
    'Nasugbu' => 'ARASOF Nasugbu',
    'Pablo Borbon' => 'Pablo Borbon',
    'Lemery' => 'Lemery',
    'Rosario' => 'Rosario',
    'Balayan' => 'Balayan',
    'Mabini' => 'Mabini',
    'San Juan' => 'San Juan',
    'Lobo' => 'Lobo'
];
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

// Build campus filter with support for both short and full campus names
$campusFilter = '';
$campusParams = [];
if (!$canViewAll && $user_campus) {
    if ($user_campus === 'JPLPC Malvar') {
        $campusFilter = ' AND (campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($user_campus === 'ARASOF Nasugbu') {
        $campusFilter = ' AND (campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $campusFilter = ' AND campus = ?';
        $campusParams = [$user_campus];
    }
}

// Database connection using centralized config
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
} catch(PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    // Check if inventory table exists
    $tableExists = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'inventory'")->fetchColumn();
    
    if (!$tableExists) {
        ob_clean();
        echo json_encode([
            'success' => true,
            'costumes' => [],
            'equipment' => []
        ]);
        exit;
    }

    // Fetch costumes
    $costumesSQL = "SELECT * FROM inventory WHERE category = 'costume' AND (status IS NULL OR status != 'archived')" . $campusFilter . " ORDER BY created_at DESC";
    $costumesStmt = $pdo->prepare($costumesSQL);
    $costumesStmt->execute($campusParams);
    $costumes = $costumesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each costume, fetch all active borrowers and calculate quantities
    foreach ($costumes as &$costume) {
        // Get total borrowed quantity for this item
        $borrowedQtySQL = "
            SELECT COALESCE(SUM(
                COALESCE((borrowed_item->>'quantity')::integer, 1)
            ), 0) as total_borrowed
            FROM borrowing_requests br
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                    THEN br.approved_items::jsonb ELSE '[]'::jsonb END
            ) AS borrowed_item
            WHERE br.status = 'approved'
            AND br.current_status IN ('active', 'pending_return')
            AND (borrowed_item->>'id')::integer = ?
        ";
        $borrowedQtyStmt = $pdo->prepare($borrowedQtySQL);
        $borrowedQtyStmt->execute([$costume['id']]);
        $borrowedQty = $borrowedQtyStmt->fetchColumn() ?: 0;
        
        // Use the database values correctly:
        // - quantity = total quantity (fixed, never changes)
        // - available_quantity = what's currently available to borrow
        // - borrowed = calculated from borrowing_requests
        $total_qty = intval($costume['quantity']);
        $available_qty = intval($costume['available_quantity']);
        
        // Override the calculated borrowed quantity with database calculation
        $costume['total_quantity'] = $total_qty;
        $costume['available_quantity'] = $available_qty;
        $costume['borrowed_quantity'] = $borrowedQty;
        
        $borrowersSQL = "
            SELECT 
                br.student_name,
                br.dates_of_use as borrow_date,
                br.due_date as return_due_date,
                br.student_email,
                br.approved_items
            FROM borrowing_requests br
            WHERE br.status = 'approved'
                AND br.current_status IN ('active', 'pending_return')
                AND EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(
                        CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                            THEN br.approved_items::jsonb ELSE '[]'::jsonb END
                    ) AS borrower_item
                    WHERE borrower_item->>'id' = CAST(? AS text)
                )
            ORDER BY br.dates_of_use DESC
        ";
        $borrowersStmt = $pdo->prepare($borrowersSQL);
        $borrowersStmt->execute([$costume['id']]);
        $costume['borrowers'] = $borrowersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For backward compatibility, set single borrower fields if there's at least one
        if (!empty($costume['borrowers'])) {
            $costume['borrower_name'] = $costume['borrowers'][0]['student_name'];
            $costume['borrow_date'] = $costume['borrowers'][0]['borrow_date'];
            $costume['return_due_date'] = $costume['borrowers'][0]['return_due_date'];
            $costume['borrower_email'] = $costume['borrowers'][0]['student_email'];
        }
    }

    // Fetch equipment with campus filtering
    $equipmentSQL = "SELECT * FROM inventory WHERE category = 'equipment' AND (status IS NULL OR status != 'archived')" . $campusFilter . " ORDER BY created_at DESC";
    $equipmentStmt = $pdo->prepare($equipmentSQL);
    $equipmentStmt->execute($campusParams);
    $equipment = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each equipment, fetch all active borrowers and calculate quantities
    foreach ($equipment as &$item) {
        // Get total borrowed quantity for this item
        $borrowedQtySQL = "
            SELECT COALESCE(SUM(
                COALESCE((borrowed_item->>'quantity')::integer, 1)
            ), 0) as total_borrowed
            FROM borrowing_requests br
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                    THEN br.approved_items::jsonb ELSE '[]'::jsonb END
            ) AS borrowed_item
            WHERE br.status = 'approved'
            AND br.current_status IN ('active', 'pending_return')
            AND (borrowed_item->>'id')::integer = ?
        ";
        $borrowedQtyStmt = $pdo->prepare($borrowedQtySQL);
        $borrowedQtyStmt->execute([$item['id']]);
        $borrowedQty = $borrowedQtyStmt->fetchColumn() ?: 0;
        
        // Use the database values correctly:
        // - quantity = total quantity (fixed, never changes)
        // - available_quantity = what's currently available to borrow
        // - borrowed = calculated from borrowing_requests
        $total_qty = intval($item['quantity']);
        $available_qty = intval($item['available_quantity']);
        
        // Override the calculated borrowed quantity with database calculation
        $item['total_quantity'] = $total_qty;
        $item['available_quantity'] = $available_qty;
        $item['borrowed_quantity'] = $borrowedQty;
        
        $borrowersSQL = "
            SELECT 
                br.student_name,
                br.dates_of_use as borrow_date,
                br.due_date as return_due_date,
                br.student_email,
                br.approved_items
            FROM borrowing_requests br
            WHERE br.status = 'approved'
                AND br.current_status IN ('active', 'pending_return')
                AND EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(
                        CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                            THEN br.approved_items::jsonb ELSE '[]'::jsonb END
                    ) AS borrower_item
                    WHERE borrower_item->>'id' = CAST(? AS text)
                )
            ORDER BY br.dates_of_use DESC
        ";
        $borrowersStmt = $pdo->prepare($borrowersSQL);
        $borrowersStmt->execute([$item['id']]);
        $item['borrowers'] = $borrowersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For backward compatibility, set single borrower fields if there's at least one
        if (!empty($item['borrowers'])) {
            $item['borrower_name'] = $item['borrowers'][0]['student_name'];
            $item['borrow_date'] = $item['borrowers'][0]['borrow_date'];
            $item['return_due_date'] = $item['borrowers'][0]['return_due_date'];
            $item['borrower_email'] = $item['borrowers'][0]['student_email'];
        }
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'costumes' => $costumes,
        'equipment' => $equipment
    ]);

} catch(PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()]);
}
?>