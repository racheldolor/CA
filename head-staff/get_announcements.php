<?php
session_start();
require_once '../config/database.php';

// Check if user is authenticated as staff
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'director'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get single announcement by ID
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ? AND is_active = 1");
        $stmt->execute([$_GET['id']]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($announcement) {
            echo json_encode(['success' => true, 'announcement' => $announcement]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
        }
        exit;
    }
    
    // Get all announcements for staff (only active ones)
    $stmt = $pdo->prepare("
        SELECT a.*, 
               u.first_name, 
               u.last_name,
               TO_CHAR(a.created_at, 'FMMonth DD, YYYY at HH12:MI AM') as formatted_created_at
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.id
        WHERE a.is_active = 1
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
    
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'announcements' => $announcements
    ]);
    
} catch (Exception $e) {
    error_log("Error getting announcements: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while fetching announcements']);
}
?>
