<?php
session_start();
require_once '../config/database.php';
require_once '../services/NotificationService.php';

// Check if user is authenticated as staff
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'director'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get form data
    $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $target_audience = $_POST['target_audience'] ?? 'all';
    $target_campus = $_POST['target_campus'] ?? 'all';
    $target_cultural_group = $_POST['target_cultural_group'] ?? '["all"]';
    $publish_date = $_POST['publish_date'] ?? date('Y-m-d');
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $priority = $_POST['priority'] ?? 'medium';
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $user_id = $_SESSION['user_id'];
    $wasPublished = false;
    
    // Validate required fields
    if (empty($title) || empty($content)) {
        echo json_encode(['success' => false, 'message' => 'Title and content are required']);
        exit;
    }
    
    // Update existing announcement
    if ($id) {
        $previousStmt = $pdo->prepare('SELECT is_published FROM announcements WHERE id = ?');
        $previousStmt->execute([$id]);
        $wasPublished = (int)$previousStmt->fetchColumn() === 1;

        $stmt = $pdo->prepare("
            UPDATE announcements SET
                title = ?,
                content = ?,
                target_audience = ?,
                target_campus = ?,
                target_cultural_group = ?,
                publish_date = ?,
                expiry_date = ?,
                priority = ?,
                is_pinned = ?,
                is_published = ?,
                is_active = 1,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $title,
            $content,
            $target_audience,
            $target_campus,
            $target_cultural_group,
            $publish_date,
            $expiry_date,
            $priority,
            $is_pinned,
            $is_published,
            $user_id,
            $id
        ]);
        
        if ($result) {
            if ($is_published && !$wasPublished) {
                NotificationService::sendAnnouncement($pdo, $id);
            }
            error_log("Announcement updated successfully. ID: $id, User: $user_id");
            echo json_encode(['success' => true, 'message' => 'Announcement updated successfully']);
        } else {
            error_log("Failed to update announcement. ID: $id");
            echo json_encode(['success' => false, 'message' => 'Failed to update announcement']);
        }
    }
    // Create new announcement
    else {
        $stmt = $pdo->prepare("
            INSERT INTO announcements (
                title, content, target_audience, target_campus, 
                target_cultural_group, publish_date, expiry_date, 
                priority, is_pinned, is_published, is_active, 
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $title,
            $content,
            $target_audience,
            $target_campus,
            $target_cultural_group,
            $publish_date,
            $expiry_date,
            $priority,
            $is_pinned,
            $is_published,
            $user_id
        ]);
        
        if ($result) {
            $new_id = $pdo->lastInsertId();
            if ($is_published) {
                NotificationService::sendAnnouncement($pdo, (int)$new_id);
            }
            error_log("Announcement created successfully. ID: $new_id, User: $user_id, Title: $title");
            echo json_encode(['success' => true, 'message' => 'Announcement created successfully', 'id' => $new_id]);
        } else {
            error_log("Failed to create announcement. User: $user_id, Title: $title");
            echo json_encode(['success' => false, 'message' => 'Failed to create announcement']);
        }
    }
    
} catch (Exception $e) {
    error_log("Error saving announcement: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
