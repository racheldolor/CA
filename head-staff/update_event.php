<?php
session_start();
require_once '../services/NotificationService.php';
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
$user_campus = $_SESSION['user_campus'] ?? null;

$canManage = true;

// Check write permission
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update events']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get form data
    $event_id = $_POST['event_id'] ?? '';
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $location = $_POST['location'] ?? '';
    $campus = $_POST['municipality'] ?? '';
    $category = $_POST['category'] ?? '';
    $cultural_groups = $_POST['cultural_groups'] ?? [];
    
    // Validate required fields
    if (empty($event_id) || empty($title) || empty($description) || empty($start_date) || empty($end_date) || empty($location) || empty($category)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit;
    }
    
    // Validate dates
    if (strtotime($start_date) > strtotime($end_date)) {
        echo json_encode(['success' => false, 'message' => 'Start date cannot be after end date']);
        exit;
    }
    
    // Check if event exists and user has permission to edit
    $stmt = $pdo->prepare("SELECT id, event_poster, venue, campus, title, description, start_date, end_date, location, category, cultural_groups, status FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $existing_event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        exit;
    }
    
    // Auto-set campus for non-Pablo Borbon users
    $canChooseCampus = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));
    
    // Verify campus access for campus-specific users
    if (!$canChooseCampus) {
        if ($user_campus && $existing_event['campus'] !== $user_campus) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to update this event']);
            exit;
        }
        // Enforce campus for the update
        $campus = $user_campus;
    }
    
    // Handle file upload if present
    $event_poster = $existing_event['event_poster']; // Keep existing image by default
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/events/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $new_filename = 'event_' . time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            // Delete old image if it exists
            if ($event_poster && file_exists('../' . $event_poster)) {
                unlink('../' . $event_poster);
            }
            $event_poster = 'assets/events/' . $new_filename;
        }
    }
    
    // Convert cultural groups array to JSON
    $cultural_groups_json = json_encode($cultural_groups);
    
    // Update event in database with campus field
    $stmt = $pdo->prepare("
        UPDATE events 
        SET title = ?, description = ?, start_date = ?, end_date = ?, location = ?, 
            venue = ?, category = ?, cultural_groups = ?, event_poster = ?, campus = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $title,
        $description,
        $start_date,
        $end_date,
        $location,
        $location,
        $category,
        $cultural_groups_json,
        $event_poster,
        $campus,
        $event_id
    ]);
    
    if ($result) {
        $changedFields = ['title', 'description', 'start_date', 'end_date', 'location', 'category', 'cultural_groups', 'campus'];
        $eventChanged = false;
        foreach ($changedFields as $field) {
            $newValue = $field === 'cultural_groups' ? $cultural_groups_json : ($field === 'campus' ? $campus : $$field);
            if ((string)($existing_event[$field] ?? '') !== (string)$newValue) {
                $eventChanged = true;
                break;
            }
        }
        if ($eventChanged && in_array((string)$existing_event['status'], ['published', 'ongoing'], true)) {
            $version = hash('sha256', json_encode([
                $title, $description, $start_date, $end_date, $location,
                $category, $cultural_groups_json, $campus
            ]));
            NotificationService::sendEvent($pdo, (int)$event_id, 'event_updated', $version);
        }
        echo json_encode([
            'success' => true, 
            'message' => 'Event updated successfully!'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update event']);
    }
    
} catch (Exception $e) {
    error_log("Error updating event: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>