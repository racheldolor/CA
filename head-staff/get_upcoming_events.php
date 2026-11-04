<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is authenticated
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'];
$user_email = $_SESSION['user_email'] ?? '';
$user_campus = $_SESSION['user_campus'] ?? null;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

try {
    $pdo = getDBConnection();
    
    // Check if campus column exists in events table
    try {
        $checkColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'events' AND column_name = 'campus'");
        $checkColumn->execute();
        $hasCampusColumn = (bool)$checkColumn->fetchColumn();
    } catch (Exception $e) {
        $hasCampusColumn = false;
    }
    
    // Build campus filter based on column availability
    $campusCondition = '';
    $campusParams = [];
    if (!$canViewAll && $user_campus && $hasCampusColumn) {
        $campusCondition = ' AND campus = ?';
        $campusParams[] = $user_campus;
    }
    
    // Get ALL upcoming events (from today onwards)
    $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 50;
    
    // Build SELECT clause based on column availability
    $selectClause = $hasCampusColumn ? "id, title, description, start_date, end_date, location, venue, category, cultural_groups, event_poster, status, campus" : "id, title, description, start_date, end_date, location, venue, category, cultural_groups, event_poster, status, NULL as campus";
    
    // Get all upcoming events ordered by start date
    $query = "
        SELECT 
            $selectClause
        FROM events 
        WHERE status IN ('published', 'ongoing', 'draft') 
        AND status != 'archived'
        AND start_date >= CURRENT_DATE
        " . $campusCondition . "
        ORDER BY start_date ASC, title ASC
        LIMIT $limit
    ";
    
    $stmt = $pdo->prepare($query);
    
    // Bind campus parameter if needed (using positional)
    if (!empty($campusParams)) {
        $stmt->execute($campusParams);
    } else {
        $stmt->execute();
    }
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process events data
    foreach ($events as &$event) {
        // Decode cultural groups from JSON
        $event['cultural_groups'] = json_decode($event['cultural_groups'], true) ?? [];
        
        // Format dates
        $event['start_date_formatted'] = date('M j, Y', strtotime($event['start_date']));
        $event['end_date_formatted'] = date('M j, Y', strtotime($event['end_date']));
        
        // Calculate days until event
        $days_until = (strtotime($event['start_date']) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
        $event['days_until'] = max(0, floor($days_until));
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'total_count' => count($events),
        'user_campus' => $user_campus,
        'can_view_all' => $canViewAll,
        'has_campus_column' => $hasCampusColumn
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching upcoming events (head-staff): " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_details' => $e->getMessage(),
        'user_role' => $user_role ?? 'unknown',
        'user_campus' => $user_campus ?? 'none'
    ]);
}
?>