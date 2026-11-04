<?php
session_start();
require_once '../config/database.php';

// Check if user is authenticated as student
if (!isset($_SESSION['logged_in']) || $_SESSION['user_table'] !== 'student_artists') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get student's cultural group and campus
    $studentStmt = $pdo->prepare("SELECT cultural_group, campus FROM student_artists WHERE id = ?");
    $studentStmt->execute([$_SESSION['user_id']]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }
    
    $studentCulturalGroup = $student['cultural_group'];
    $studentCampus = $student['campus'];
    
    // Get announcements filtered by cultural group and campus
    $announcementsStmt = $pdo->prepare("
        SELECT a.*
        FROM announcements a
        WHERE (
            (a.target_cultural_group LIKE ? OR a.target_cultural_group LIKE ? OR a.target_cultural_group = 'all')
            OR (a.target_audience = 'all' OR a.target_audience = 'students')
        )
        AND (a.target_campus = 'all' OR a.target_campus = ? OR a.target_campus IS NULL)
        AND a.is_active = 1
        AND a.is_published = 1
        AND (a.expiry_date IS NULL OR a.expiry_date >= CURRENT_DATE)
        ORDER BY a.is_pinned DESC, a.created_at DESC
        LIMIT 20
    ");
    
    $groupPattern1 = '%"' . $studentCulturalGroup . '"%';
    $groupPattern2 = '%' . $studentCulturalGroup . '%';
    
    $announcementsStmt->execute([$groupPattern1, $groupPattern2, $studentCampus]);
    $announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get event announcements (upcoming and ongoing events for student's group and campus)
    $eventsStmt = $pdo->prepare("
        SELECT 
            id,
            CONCAT('New Event: ', title) as title,
            CONCAT(
                'Event: ', title, '\n',
                'Date: ', TO_CHAR(start_date, 'FMMonth DD, YYYY'),
                CASE WHEN start_date != end_date THEN CONCAT(' - ', TO_CHAR(end_date, 'FMMonth DD, YYYY')) ELSE '' END, E'\\n',
                'Location: ', location, '\n',
                'Category: ', COALESCE(category, 'Event'), '\n\n',
                description
            ) as content,
            created_at,
            0 as is_pinned,
            'event' as announcement_type
        FROM events
        WHERE (cultural_groups LIKE ? OR cultural_groups LIKE ? OR cultural_groups = '[]')
        AND (campus = ? OR campus IS NULL OR campus = '')
        AND end_date >= CURRENT_DATE
        AND status IN ('published', 'ongoing')
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $eventsStmt->execute([$groupPattern1, $groupPattern2, $studentCampus]);
    $eventAnnouncements = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge announcements and event announcements
    $allAnnouncements = array_merge($announcements, $eventAnnouncements);
    
    // Sort by pinned first, then by date
    usort($allAnnouncements, function($a, $b) {
        if ($a['is_pinned'] != $b['is_pinned']) {
            return $b['is_pinned'] - $a['is_pinned'];
        }
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Limit to 20 total
    $allAnnouncements = array_slice($allAnnouncements, 0, 20);
    
    echo json_encode([
        'success' => true,
        'announcements' => $allAnnouncements,
        'student_group' => $studentCulturalGroup,
        'student_campus' => $studentCampus
    ]);
    
} catch (Exception $e) {
    error_log("Error getting announcements: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while fetching announcements']);
}
?>