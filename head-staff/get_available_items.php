<?php
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(0);

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'] ?? '', ['head', 'admin', 'director'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus = $_SESSION['user_campus'] ?? null;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

try {
    // Database connection using centralized config
    require_once __DIR__ . '/../config/database.php';
    $pdo = getDBConnection();

    // Get parameters
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';

    // Campus filter for SQL
    $campusFilter = '';
    $campusParams = [];
    if (!$canViewAll && $user_campus) {
        $campusFilter = ' AND campus = :campus';
        $campusParams['campus'] = $user_campus;
    }

    // Build base query for available items (status = 'available')
    $costumeQuery = "SELECT 
                        id, 
                        item_name as name, 
                        condition_status AS \"condition\",
                        status,
                        'costume' as type
                     FROM inventory 
                     WHERE status = 'available'" . $campusFilter;
    
    $equipmentQuery = "SELECT 
                         id, 
                         item_name as name, 
                         condition_status AS \"condition\",
                         status,
                         'equipment' as type
                       FROM inventory 
                       WHERE status = 'available' AND category = 'equipment'" . $campusFilter;

    $costumeParams = $campusParams;
    $equipmentParams = $campusParams;

    // Add search filter if provided
    if (!empty($search)) {
        $costumeQuery .= " AND item_name LIKE :search";
        $equipmentQuery .= " AND item_name LIKE :search";
        $costumeParams['search'] = "%$search%";
        $equipmentParams['search'] = "%$search%";
    }

    // Add category filter for costumes (exclude equipment category)
    $costumeQuery .= " AND (category != 'equipment' OR category IS NULL)";

    // Order results
    $costumeQuery .= " ORDER BY item_name ASC";
    $equipmentQuery .= " ORDER BY item_name ASC";

    $costumes = [];
    $equipment = [];

    // Fetch costumes if no category filter or category is 'costume'
    if (empty($category) || $category === 'costume') {
        $stmt = $pdo->prepare($costumeQuery);
        $stmt->execute($costumeParams);
        $costumes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch equipment if no category filter or category is 'equipment'
    if (empty($category) || $category === 'equipment') {
        $stmt = $pdo->prepare($equipmentQuery);
        $stmt->execute($equipmentParams);
        $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return the results
    echo json_encode([
        'success' => true,
        'costumes' => $costumes,
        'equipment' => $equipment,
        'total_costumes' => count($costumes),
        'total_equipment' => count($equipment)
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>