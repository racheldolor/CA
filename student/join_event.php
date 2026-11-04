<?php
session_start();
require_once '../config/database.php';

// Set proper headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Check if user is authenticated as student
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_table'] !== 'student_artists') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $pdo = getDBConnection();
    $student_id = $_SESSION['user_id'];

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $event_id = $input['event_id'] ?? null;
    $action = $input['action'] ?? 'join'; // 'join' or 'leave'

    if (!$event_id) {
        echo json_encode(['success' => false, 'message' => 'Event ID is required']);
        exit;
    }

    // Check if event exists and is still available for joining
    $stmt = $pdo->prepare("
        SELECT id, title, start_date, end_date, status 
        FROM events 
        WHERE id = ? AND status IN ('published', 'ongoing', 'draft')
    ");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found or no longer available']);
        exit;
    }

    // Check if event has already started
    $now = new DateTime();
    $start_date = new DateTime($event['start_date']);
    
    if ($action === 'join' && $now >= $start_date) {
        echo json_encode(['success' => false, 'message' => 'Cannot join event that has already started']);
        exit;
    }

    if ($action === 'join') {
        // Try to join the event
        try {
            $stmt = $pdo->prepare("
                INSERT INTO event_participants (event_id, student_id, attendance_status, registration_date) 
                VALUES (?, ?, 'registered', CURRENT_TIMESTAMP)
                ON CONFLICT (event_id, student_id) DO UPDATE SET
                attendance_status = 'registered', 
                registration_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$event_id, $student_id]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Successfully joined the event!',
                'action' => 'joined'
            ]);
            
        } catch (Exception $e) {
            error_log("Join event error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to join event: ' . $e->getMessage()]);
        }
        
    } else if ($action === 'leave') {
        // Leave the event (mark as cancelled)
        $stmt = $pdo->prepare("
            UPDATE event_participants 
            SET attendance_status = 'cancelled', 
                cancelled_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE event_id = ? AND student_id = ? AND attendance_status != 'cancelled'
        ");
        $result = $stmt->execute([$event_id, $student_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Successfully left the event',
                'action' => 'left'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'You are not registered for this event']);
        }
    }

} catch (Exception $e) {
    error_log("Error in join_event.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request']);
}
?>