<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is authenticated as student
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login as a student.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get student's cultural group and campus
    $studentStmt = $pdo->prepare("SELECT cultural_group, campus FROM student_artists WHERE id = ?");
    $studentStmt->execute([$_SESSION['user_id']]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student profile not found. Please contact administrator.']);
        exit;
    }
    
    $studentCulturalGroup = $student['cultural_group'] ?? '';
    $studentCampus = $student['campus'] ?? '';
    
    // Check if campus column exists in events table, if not use venue
    try {
        $checkColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'events' AND column_name = 'campus'");
        $checkColumn->execute();
        $hasCampusColumn = (bool)$checkColumn->fetchColumn();
    } catch (Exception $e) {
        $hasCampusColumn = false;
    }
    
    // Build query based on available columns
    if ($hasCampusColumn) {
        // Use campus column for filtering
        $campusFilter = "AND (e.campus = ? OR e.campus IS NULL OR e.campus = '')";
    } else {
        // Fallback to venue column for campus filtering
        $campusFilter = "AND (e.venue LIKE ? OR e.venue IS NULL OR e.venue = '')";
    }
    
    // Get events filtered by cultural group and campus, including participation status
    $query = "
        SELECT e.*, 
               (ep.student_id IS NOT NULL AND ep.attendance_status != 'cancelled') as has_joined,
               ep.registration_date as joined_at,
               ep.attendance_status,
               CASE 
                WHEN e.end_date < CURRENT_DATE THEN 'ended'
                WHEN e.start_date <= CURRENT_DATE AND e.end_date >= CURRENT_DATE THEN 'ongoing'
                   ELSE 'upcoming'
               END as event_status
        FROM events e
        LEFT JOIN event_participants ep ON e.id = ep.event_id AND ep.student_id = ?
        WHERE (e.cultural_groups LIKE ? OR e.cultural_groups LIKE ? OR e.cultural_groups = '[]' OR e.cultural_groups IS NULL)
        $campusFilter
        AND e.end_date >= CURRENT_DATE
        AND e.status IN ('published', 'ongoing')
        ORDER BY e.start_date ASC
    ";
    
    $eventsStmt = $pdo->prepare($query);
    
    $groupPattern1 = '%"' . $studentCulturalGroup . '"%';
    $groupPattern2 = '%' . $studentCulturalGroup . '%';
    
    // Execute with appropriate campus parameter
    if ($hasCampusColumn) {
        $eventsStmt->execute([$_SESSION['user_id'], $groupPattern1, $groupPattern2, $studentCampus]);
    } else {
        // Use LIKE for venue matching
        $campusPattern = '%' . $studentCampus . '%';
        $eventsStmt->execute([$_SESSION['user_id'], $groupPattern1, $groupPattern2, $campusPattern]);
    }
    
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format events for display
    foreach ($events as &$event) {
        // Parse cultural groups JSON
        $culturalGroupsData = $event['cultural_groups'];
        if (is_string($culturalGroupsData)) {
            $decoded = json_decode($culturalGroupsData, true);
            $event['cultural_groups_array'] = is_array($decoded) ? $decoded : [];
        } else {
            $event['cultural_groups_array'] = [];
        }
        
        // Format dates
        $event['formatted_start_date'] = date('F j, Y', strtotime($event['start_date']));
        $event['formatted_end_date'] = date('F j, Y', strtotime($event['end_date']));
        $event['is_multi_day'] = $event['start_date'] !== $event['end_date'];
        
        // Join status
        $event['has_joined'] = (bool)$event['has_joined'];
        $event['can_join'] = !$event['has_joined'] && $event['event_status'] === 'upcoming';
        $event['show_join_button'] = $event['event_status'] === 'upcoming';
        $event['joined_at_formatted'] = $event['joined_at'] ? date('F j, Y g:i A', strtotime($event['joined_at'])) : null;
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'student_group' => $studentCulturalGroup,
        'student_campus' => $studentCampus,
        'has_campus_column' => $hasCampusColumn,
        'total_events' => count($events)
    ]);
    
} catch (Exception $e) {
    error_log("Error getting events: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_details' => $e->getMessage()
    ]);
}
?>