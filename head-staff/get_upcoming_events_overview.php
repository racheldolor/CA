<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus = $_SESSION['user_campus'] ?? null;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

// Build campus filter
$campusFilter = '';
$campusParams = [];
if (!$canViewAll && $user_campus) {
    $campusFilter = ' AND e.campus = ?';
    $campusParams[] = $user_campus;
}

header('Content-Type: application/json');

try {
    $pdo = getDBConnection();
    
    // Get upcoming events (next 30 days) with campus filtering
    $sql = "
        SELECT 
            e.id,
            e.title,
            e.start_date,
            e.end_date,
            e.location,
            e.category,
            e.cultural_groups,
            e.campus,
            COUNT(ep.student_id) as registered_count
        FROM events e
        LEFT JOIN event_participants ep ON e.id = ep.event_id
        WHERE e.start_date >= CURRENT_DATE
        AND e.start_date <= CURRENT_DATE + INTERVAL '30 days'
        " . $campusFilter . "
        GROUP BY e.id, e.title, e.start_date, e.end_date, e.location, e.category, e.cultural_groups, e.campus
        ORDER BY e.start_date ASC
        LIMIT 10
    ";
    $upcomingStmt = $pdo->prepare($sql);
    $upcomingStmt->execute($campusParams);
    $upcomingEvents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process cultural groups for each event
    foreach ($upcomingEvents as &$event) {
        $event['cultural_groups_array'] = json_decode($event['cultural_groups'], true) ?: [];
        $event['days_until'] = (new DateTime($event['start_date']))->diff(new DateTime())->days;
    }
    
    // Get event statistics with campus filtering
    $statsSql = "
        SELECT 
            COUNT(*) as total_upcoming,
            COUNT(CASE WHEN e.start_date <= CURRENT_DATE + INTERVAL '7 days' THEN 1 END) as this_week,
            COUNT(CASE WHEN e.category = 'training' THEN 1 END) as trainings,
            COUNT(CASE WHEN e.category = 'performance' THEN 1 END) as performances
        FROM events e
        WHERE e.start_date >= CURRENT_DATE
        AND e.start_date <= CURRENT_DATE + INTERVAL '30 days'
        " . $campusFilter . "
    ";
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($campusParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent registrations (simplified - just show events with participants) with campus filtering
    $recentSql = "
        SELECT 
            e.title as event_title,
            COUNT(ep.student_id) as new_registrations
        FROM events e
        INNER JOIN event_participants ep ON e.id = ep.event_id
        WHERE e.start_date >= CURRENT_DATE
        " . $campusFilter . "
        GROUP BY e.id, e.title
        HAVING COUNT(ep.student_id) > 0
        ORDER BY new_registrations DESC
        LIMIT 5
    ";
    $recentRegistrationsStmt = $pdo->prepare($recentSql);
    $recentRegistrationsStmt->execute($campusParams);
    $recentRegistrations = $recentRegistrationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'upcoming_events' => $upcomingEvents,
        'statistics' => $stats,
        'recent_registrations' => $recentRegistrations
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch upcoming events data: ' . $e->getMessage()
    ]);
}
?>