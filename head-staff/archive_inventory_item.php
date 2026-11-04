<?php
session_start();
require_once '../config/database.php';

// Check if user is authenticated
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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
    'Alangilan' => 'Alangilan',
    'Lipa' => 'Lipa',
    'JPLPC Malvar' => 'JPLPC Malvar',
    'ARASOF Nasugbu' => 'ARASOF Nasugbu'
];
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

$canManage = true;

// Check write permission
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to archive inventory items']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get item ID from request
    $input = json_decode(file_get_contents('php://input'), true);
    $item_id = $input['item_id'] ?? '';
    
    if (empty($item_id)) {
        echo json_encode(['success' => false, 'message' => 'Item ID is required']);
        exit;
    }
    
    // Check if inventory table exists
    $tableExists = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'inventory'")->fetchColumn();
    
    if (!$tableExists) {
        echo json_encode(['success' => false, 'message' => 'Inventory table does not exist']);
        exit;
    }
    
    // Check if item exists - only select columns that definitely exist
    $stmt = $pdo->prepare("SELECT id, item_name, campus FROM inventory WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit;
    }
    
    // Verify campus access for campus-specific users
    $canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));
    
    if (!$canViewAll) {
        // Check if item campus matches user campus (both formats)
        $item_campus = $item['campus'];
        $campus_match = false;
        
        if ($user_campus === 'JPLPC Malvar') {
            $campus_match = ($item_campus === 'JPLPC Malvar' || $item_campus === 'Malvar');
        } elseif ($user_campus === 'ARASOF Nasugbu') {
            $campus_match = ($item_campus === 'ARASOF Nasugbu' || $item_campus === 'Nasugbu');
        } else {
            $campus_match = ($item_campus === $user_campus);
        }
        
        if (!$campus_match) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to archive this item']);
            exit;
        }
    }
    
    // Check if status column exists and has 'archived' option
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'inventory' AND column_name = 'status'");
    $stmt->execute();
    $status_column = $stmt->fetchColumn();
    
    if (!$status_column) {
        // Status column doesn't exist, add it
        try {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN status varchar(20) DEFAULT 'available'");
        } catch (Exception $alter_e) {
            error_log("Error adding status column: " . $alter_e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: Could not add status column']);
            exit;
        }
    }
    
    // Archive the item by setting status to 'archived'
    $sql = "UPDATE inventory SET status = 'archived', updated_at = NOW() WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$item_id]);
    
    if ($result) {
        $item_name = $item['item_name'] ?: 'Item';
        echo json_encode([
            'success' => true, 
            'message' => 'Item "' . htmlspecialchars($item_name) . '" archived successfully! You can restore it from the Archives module.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to archive item']);
    }
    
} catch (Exception $e) {
    error_log("Error archiving inventory item: " . $e->getMessage());
    $error_msg = 'An error occurred while archiving the item';
    if (strpos($e->getMessage(), 'Column') !== false) {
        $error_msg .= '. Database structure issue detected - please contact administrator.';
    }
    echo json_encode(['success' => false, 'message' => $error_msg, 'debug' => $e->getMessage()]);
}
?>
