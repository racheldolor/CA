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
$user_campus_raw = $_SESSION['user_campus'] ?? null;

// Campus name normalization
$campus_name_map = [
    'Malvar' => 'JPLPC Malvar',
    'Nasugbu' => 'ARASOF Nasugbu',
    'Pablo Borbon' => 'Pablo Borbon',
    'Lemery' => 'Lemery',
    'Rosario' => 'Rosario',
    'Balayan' => 'Balayan',
    'Mabini' => 'Mabini',
    'San Juan' => 'San Juan',
    'Lobo' => 'Lobo'
];
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

// Build campus filter with support for both short and full campus names
$campusFilter = '';
$campusParams = [];
if (!$canViewAll && $user_campus) {
    if ($user_campus === 'JPLPC Malvar') {
        $campusFilter = ' AND (campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($user_campus === 'ARASOF Nasugbu') {
        $campusFilter = ' AND (campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $campusFilter = ' AND campus = ?';
        $campusParams = [$user_campus];
    }
}

header('Content-Type: application/json');

try {
    $pdo = getDBConnection();
    
    // Get student artist participation statistics with campus filtering
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT sa.id) as total_students,
            COUNT(DISTINCT CASE WHEN sa.cultural_group IS NOT NULL AND sa.cultural_group != '' THEN sa.id END) as assigned_students,
            COUNT(DISTINCT CASE WHEN sa.cultural_group IS NULL OR sa.cultural_group = '' THEN sa.id END) as unassigned_students,
            COUNT(DISTINCT CASE WHEN sa.status = 'active' THEN sa.id END) as active_students,
            COUNT(DISTINCT CASE WHEN sa.status = 'suspended' THEN sa.id END) as suspended_students
        FROM student_artists sa
        WHERE sa.status IN ('active', 'suspended')" . $campusFilter . "
    ");
    $stmt->execute($campusParams);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get event participation data with campus filtering
    $participationStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ep.event_id) as events_with_participation,
            COUNT(DISTINCT ep.student_id) as students_participated,
            COUNT(*) as total_participations
        FROM event_participants ep
        INNER JOIN student_artists sa ON ep.student_id = sa.id
        WHERE sa.status = 'active'" . $campusFilter . "
    ");
    $participationStmt->execute($campusParams);
    $participation = $participationStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate participation rate based on actual data
    $totalActiveStudents = (int)$stats['active_students'];
    $studentsParticipated = (int)$participation['students_participated'];
    $participationRate = $totalActiveStudents > 0 ? round(($studentsParticipated / $totalActiveStudents) * 100) : 0;
    $participation['participation_rate'] = $participationRate;
    
    // Get recent participation activity (last 30 days) with campus filtering
    $eventCampusFilter = $campusFilter ? str_replace('campus', 'e.campus', $campusFilter) : '';
    $recentStmt = $pdo->prepare("
        SELECT 
            e.title as event_title,
            e.start_date,
            COUNT(ep.student_id) as participants_count,
            COUNT(ep.student_id) as actual_participants
        FROM events e
        LEFT JOIN event_participants ep ON e.id = ep.event_id
        WHERE e.start_date >= CURRENT_TIMESTAMP - (? * INTERVAL '1 day')" . $eventCampusFilter . "
        GROUP BY e.id, e.title, e.start_date
        ORDER BY e.start_date DESC
        LIMIT 5
    ");
    $recentStmt->execute($campusParams);
    $recentActivity = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cultural group participation breakdown with campus filtering
    $groupStmt = $pdo->prepare("
        SELECT 
            sa.cultural_group,
            COUNT(DISTINCT sa.id) as group_size,
            COUNT(DISTINCT ep.event_id) as events_participated,
            COUNT(ep.id) as total_participations
        FROM student_artists sa
        LEFT JOIN event_participants ep ON sa.id = ep.student_id
        WHERE sa.status = 'active' AND sa.cultural_group IS NOT NULL AND sa.cultural_group != ''" . $campusFilter . "
        GROUP BY sa.cultural_group
        ORDER BY total_participations DESC
    ");
    $groupStmt->execute($campusParams);
    $groupBreakdown = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'statistics' => $stats,
        'participation' => $participation,
        'recent_activity' => $recentActivity,
        'group_breakdown' => $groupBreakdown
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch student artist overview data: ' . $e->getMessage()
    ]);
}
?>