<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'director'])) {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();

// === RBAC: Get user's campus and determine access level ===
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus_raw = $_SESSION['user_campus'] ?? null;

// Normalize campus names to full format
$campus_name_map = [
    'Malvar' => 'JPLPC Malvar',
    'Nasugbu' => 'ARASOF Nasugbu',
    'Pablo Borbon' => 'Pablo Borbon',
    'Alangilan' => 'Alangilan',
    'Lipa' => 'Lipa',
    'JPLPC Malvar' => 'JPLPC Malvar',
    'ARASOF Nasugbu' => 'ARASOF Nasugbu'
];
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

// Display campus name
// Director role should display Pablo Borbon
if ($user_role === 'director') {
    $display_campus = 'Pablo Borbon';
} else {
    $display_campus = $user_campus;
}

// Director role has view-only access to all campuses
$isDirector = ($user_role === 'director');

// Pablo Borbon Head users now have full management across all campuses
$isPabloBorbonHead = ($user_role === 'head' && $user_campus === 'Pablo Borbon');

// Campus filtering logic:
// - Admin: see all campuses
// - Pablo Borbon head: see all campuses with full management
// - Director: see all campuses (view-only)
// - Other campus head: see only their campus
$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head') || $user_role === 'director');
$canManage = !$isDirector; // Only Director is view-only
$canManageEvents = $canManage; // Directors can view events but cannot create or edit them

// Build campus filter for SQL
if ($canViewAll) {
    $campusFilter = '1=1'; // No filter - see all
    $campusParams = [];
} else {
    // For Malvar and Nasugbu, check both short and full names
    if ($user_campus === 'JPLPC Malvar') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($user_campus === 'ARASOF Nasugbu') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $campusFilter = 'campus = ?';
        $campusParams = [$user_campus];
    }
}

// Pagination for student artists
$students_per_page = 5;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Determine effective campus filter:
// - If URL provides campus_filter, use it.
// - Else: if user can view all, default to Pablo Borbon (admin case).
// - Else default to the user's assigned campus so that non-Pablo users see only their campus.
$campus_filter = isset($_GET['campus_filter']) ? trim($_GET['campus_filter']) : (
    !empty($canViewAll) ? 'Pablo Borbon' : ($user_campus ?? 'Pablo Borbon')
);

// Build campus filter for student queries
if (!empty($campus_filter)) {
    // Use the selected campus filter from dropdown or the user's campus
    if ($campus_filter === 'JPLPC Malvar') {
        $studentCampusFilter = '(campus = ? OR campus = ?)';
        $studentCampusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($campus_filter === 'ARASOF Nasugbu') {
        $studentCampusFilter = '(campus = ? OR campus = ?)';
        $studentCampusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $studentCampusFilter = 'campus = ?';
        $studentCampusParams = [$campus_filter];
    }
} else {
    // Fallback - should not normally happen
    if ($user_campus === 'JPLPC Malvar') {
        $studentCampusFilter = '(campus = ? OR campus = ?)';
        $studentCampusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($user_campus === 'ARASOF Nasugbu') {
        $studentCampusFilter = '(campus = ? OR campus = ?)';
        $studentCampusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $studentCampusFilter = 'campus = ?';
        $studentCampusParams = [$user_campus ?? 'Pablo Borbon'];
    }
}

// Get dashboard statistics with campus filtering
try {
    // Count student artists from student_artists table (active and suspended)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_artists WHERE status IN ('active', 'suspended') AND $campusFilter");
    $stmt->execute($campusParams);
    $student_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count distinct cultural groups assigned to student artists
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT cultural_group) as count FROM student_artists 
                          WHERE cultural_group IS NOT NULL AND cultural_group != '' AND $campusFilter");
    $stmt->execute($campusParams);
    $cultural_groups = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count scheduled events (future events that are not yet completed) with campus filtering
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM events WHERE start_date >= CURRENT_DATE AND " . $campusFilter);
    $stmt->execute($campusParams);
    $scheduled_events = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count damaged items (items in repair_items table with status 'damaged' or 'under_repair')
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM repair_items WHERE repair_status IN ('damaged', 'under_repair')");
    $stmt->execute();
    $worn_costumes = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count for cultural groups card (matches the API and campus distribution chart - use same campus filter)
    // Use the same campus filter logic as the student queries for consistency
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_artists WHERE status = 'active' AND $studentCampusFilter");
    $stmt->execute($studentCampusParams);
    $cultural_groups_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch (Exception $e) {
    // If any query fails, set safe default values
    $student_count = $student_count ?? 0;
    $cultural_groups = $cultural_groups ?? 0;
    $scheduled_events = $scheduled_events ?? 0;
    $worn_costumes = 0;
    $cultural_groups_count = 0;
    
    // Log the error for debugging
    error_log("Dashboard statistics error: " . $e->getMessage());
}

// Get student artists with pagination (active and suspended) - with campus filtering
try {
    $where_conditions = ["status IN ('active', 'suspended')", $studentCampusFilter];
    $params = $studentCampusParams;
    
    if (!empty($search)) {
        $where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR sr_code LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM student_artists $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_students = $count_stmt->fetchColumn();
    
    // Calculate pagination
    $total_pages = ceil($total_students / $students_per_page);
    $offset = ($current_page - 1) * $students_per_page;
    
    // Get paginated students - try with cultural_group column first
        $sql = "SELECT id, sr_code, first_name, middle_name, last_name, email, campus, program, year_level, cultural_group, status, created_at 
            FROM student_artists $where_clause
            ORDER BY id ASC
            LIMIT $students_per_page OFFSET $offset";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // If cultural_group column doesn't exist, add it and try again
        try {
            $pdo->exec("ALTER TABLE student_artists ADD COLUMN cultural_group VARCHAR(100) DEFAULT NULL");
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            // If still failing, try without cultural_group column
            $sql = "SELECT id, sr_code, first_name, middle_name, last_name, email, campus, program, year_level, status, created_at 
                    FROM student_artists $where_clause 
                    ORDER BY id ASC 
                    LIMIT $students_per_page OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Add empty cultural_group to each student
            foreach ($students as &$student) {
                $student['cultural_group'] = null;
            }
        }
    }
    
} catch (Exception $e) {
    $students = [];
    $total_students = 0;
    $total_pages = 0;
    // Debug: Log the error
    error_log("Error fetching students: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net;">
    <title>Staff Dashboard - Culture and Arts - BatStateU TNEU</title>
    <!-- Chart.js Library for Interactive Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Prevent background scrolling when modals are open */
        body.modal-open {
            overflow: hidden;
            position: fixed;
            width: 100%;
        }

        /* Header */
        .header {
            background: white;
            color: #dc2626;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #e0e0e0;
            width: 100%;
            max-width: 100vw;
            box-sizing: border-box;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .header-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            color: #333;
        }

        .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* Main Container */
        .main-container {
            display: flex;
            min-height: calc(100vh - 70px);
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            max-width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            min-height: calc(100vh - 70px);
            position: fixed;
            top: 70px;
            left: 0;
            z-index: 50;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-greeting {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            position: relative;
            overflow: hidden;
        }

        .sidebar-greeting::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .sidebar-greeting::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .sidebar-greeting h3 {
            margin: 0 0 0.5rem 0;
            color: white;
            font-weight: 700;
            position: relative;
            z-index: 1;
            line-height: 1.2;
        }

        .greeting-hi {
            font-size: 1.8rem;
            font-weight: 400;
        }

        .greeting-name {
            font-size: 1.8rem;
        }

        .sidebar-greeting p {
            margin: 0 0 0 0.5rem;
            font-style: bold;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.95);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .nav-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.5rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: #f8f9fa;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        .nav-link.active {
            background: #fee2e2;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
            width: 100%;
            height: 100%;
        }

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: 100%;
        }

        .dashboard-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #dc2626;
            transition: transform 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }

        .card-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #dc2626;
        }

        .card-subtitle {
            font-size: 0.85rem;
            color: #888;
            margin-top: 0.5rem;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: 100%;
        }

        .content-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .panel-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .expand-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .expand-btn:hover {
            background: #e9ecef;
            color: #dc2626;
        }

        .panel-content {
            padding: 2rem;
            text-align: center;
            color: #888;
            font-style: italic;
        }

        /* Event Participation Table - Seamless Connection */
        #eventParticipationContent {
            padding: 0;
            text-align: left;
            color: inherit;
            font-style: normal;
        }

        .participation-table-container {
            margin: 0;
        }

        .participation-table-container table {
            border-radius: 0 !important;
            box-shadow: none !important;
            margin-top: -1px; /* Pull table up to merge with header */
        }

        /* Event Evaluations Analytics - Remove Italic Styling */
        #evaluationAnalyticsContent,
        #evaluationAnalyticsContent *,
        .evaluation-stats-grid,
        .evaluation-stats-grid *,
        .stat-card,
        .stat-card *,
        .comments-analysis,
        .comments-analysis * {
            font-style: normal !important;
        }

        #evaluationAnalyticsContent {
            text-align: center;
        }

        /* Center descriptions in evaluation analytics */
        #evaluationAnalyticsContent .stat-card,
        #evaluationAnalyticsContent .comments-analysis,
        #evaluationAnalyticsContent p,
        #evaluationAnalyticsContent .performance-text,
        #evaluationAnalyticsContent .strengths-text,
        #evaluationAnalyticsContent .recommendations-text {
            text-align: center !important;
        }

        /* Chart Area */
        .chart-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1.5rem;
        }

        .chart-header {
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .chart-content {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
        }

        .add-btn {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .add-btn.secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }

        .add-btn.secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Condition Badges */
        .condition-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .condition-badge.condition-good {
            background: #d1fae5;
            color: #065f46;
        }

        .condition-badge.condition-worn-out {
            background: #fef3c7;
            color: #92400e;
        }

        .condition-badge.condition-bad {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Status badges for inventory */
        .status-badge.status-available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.status-borrowed {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.status-maintenance {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Action Buttons */
        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            margin: 0 0.25rem;
            transition: all 0.3s ease;
        }

        .action-btn.small {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        .action-btn.approve {
            background: #10b981;
            color: white;
        }

        .action-btn.approve:hover {
            background: #059669;
        }

        .action-btn.reject {
            background: #ef4444;
            color: white;
        }

        .action-btn.reject:hover {
            background: #dc2626;
        }

        .action-btn.view {
            background: #6b7280;
            color: white;
        }

        .action-btn.view:hover {
            background: #4b5563;
        }

        /* Table Styles for Borrow Requests */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            width: 100%;
            max-width: 100%;
        }

        .table-container table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .table-container th,
        .table-container td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .table-container th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        .table-container tr:hover {
            background: #f9fafb;
        }

        /* Compact row style for denser inventory lists */
        .compact-row > div {
            padding: 0 0.4rem !important;
            font-size: 0.9rem;
            line-height: 1.2;
        }

        /* Student Profiles Specific Styles */
        .search-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .search-container {
            max-width: 300px;
        }

        .search-container label {
            display: block;
            margin-bottom: 0.5rem;
            color: #666;
            font-weight: 500;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-bottom: 2px solid #dc2626;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .student-overview {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .overview-left {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .chart-placeholder {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #ddd;
        }

        .chart-content {
            text-align: center;
            color: #888;
        }

        .chart-content p {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .chart-content small {
            font-size: 0.875rem;
            color: #aaa;
        }

        /* Campus Distribution Styles */
        .campus-distribution {
            display: flex;
            gap: 2rem;
            height: 300px;
        }

        .chart-section {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .pie-chart-container {
            width: 200px;
            height: 200px;
            position: relative;
        }

        .pie-chart {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            position: relative;
        }

        .chart-legend {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.75rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }

        .legend-text {
            font-size: 0.9rem;
            color: #333;
        }

        .bar-chart-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .bar-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .bar-label {
            width: 80px;
            font-size: 0.85rem;
            color: #666;
            text-align: right;
        }

        .bar-track {
            flex: 1;
            height: 24px;
            background: #f0f0f0;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 12px;
            transition: width 0.8s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
        }

        .bar-value {
            font-size: 0.75rem;
            color: white;
            font-weight: 600;
        }

        .record-count-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            height: 400px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
            padding: 0.75rem;
        }

        .cultural-group-list {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            max-height: 350px;
            overflow-y: auto;
            width: 100%;
            padding: 0.25rem;
        }

        .group-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0.6rem;
            background: white;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            font-size: 0.8rem;
            min-height: 32px;
        }

        .group-name {
            font-weight: 500;
            color: #333;
            flex: 1;
            text-align: left;
        }

        .group-count {
            font-weight: 600;
            color: #dc2626;
            background: #fee2e2;
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            min-width: 30px;
            text-align: center;
        }

        /* Events Management Styles */
        .events-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: 100%;
        }

        /* When only one column exists (view-only mode) */
        .events-grid:has(.events-right:only-child) {
            grid-template-columns: 1fr;
            max-width: 100%;
            margin: 0 0 2rem;
        }

        .events-grid:has(.events-right:only-child) .events-right {
            max-width: 100%;
        }

        .events-grid:has(.events-right:only-child) .events-list {
            height: calc(100vh - 350px);
            min-height: 600px;
        }

        .events-left,
        .events-right {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .input-panel,
        .upcoming-panel {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .events-list {
            overflow-y: auto;
            padding: 1.5rem;
            height: 700px;
            min-height: 700px;
        }

        /* Ensure upcoming events list can be limited and scrolled to match input panel height */
        .events-right .events-list,
        .events-left .events-list {
            overflow-y: auto;
            height: 700px;
            min-height: 700px;
        }
        
        .events-right {
            display: flex;
            flex-direction: column;
        }
        
        .events-right .upcoming-panel {
            display: flex;
            flex-direction: column;
        }

        .panel-header-event {
            background: #4285F4;
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        .panel-header-upcoming {
            background: #28a745;
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }

        .panel-title-event,
        .panel-title-upcoming {
            margin: 0;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .event-form {
            padding: 1.5rem;
        }

        .event-form .form-group {
            margin-bottom: 1.2rem;
        }

        .event-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }

        .event-form input,
        .event-form textarea,
        .event-form select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: border-color 0.3s ease;
        }

        .event-form input::placeholder,
        .event-form textarea::placeholder {
            color: #999;
            font-size: 0.9rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .event-form input:focus,
        .event-form textarea:focus,
        .event-form select:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .event-form textarea {
            min-height: 80px;
            resize: vertical;
        }

        /* Multi-select styles */
        .multi-select-container {
            position: relative;
        }

        .multi-select-display {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 0.75rem;
            background: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 20px;
        }

        .multi-select-display:hover {
            border-color: #ccc;
        }

        .multi-select-display .placeholder {
            color: #888;
        }

        .multi-select-display .selected-groups {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .selected-group-tag {
            background: #dc2626;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .remove-tag {
            cursor: pointer;
            font-weight: bold;
        }

        .dropdown-arrow {
            transition: transform 0.3s ease;
        }

        .dropdown-arrow.open {
            transform: rotate(180deg);
        }

        .multi-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .checkbox-item:hover {
            background: #f8f9fa;
        }

        .checkbox-item input[type="checkbox"] {
            margin: 0 0.5rem 0 0;
            width: 16px;
            height: 16px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-item span {
            line-height: 1.4;
            font-size: 0.9rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .save-event-btn {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .save-event-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .empty-events {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 300px;
            text-align: center;
            color: #888;
        }

        .empty-events p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #666;
        }

        .empty-events small {
            font-size: 0.9rem;
            color: #aaa;
        }

        .event-item {
            padding: 1rem;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .event-item:hover {
            border-color: #dc2626;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
        }

        .event-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .event-date {
            color: #dc2626;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .event-location {
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .event-category {
            display: inline-block;
            background: #f8f9fa;
            color: #666;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .events-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e0e0e0;
            background: #f8f9fa;
            text-align: center;
            margin-top: auto;
            flex-shrink: 0;
        }

        .view-all-link {
            color: #dc2626;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .view-all-link:hover {
            text-decoration: underline;
        }

        /* Performer Profile Form Styles */
        .performer-profile-card {
            background: #fff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .performer-profile-card .form-header {
            background: #fff;
            padding: 1.5rem 2rem;
            border-bottom: 2px solid #333;
        }

        .performer-profile-card .form-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .performer-profile-card .form-body {
            padding: 2rem;
        }

        .performer-profile-card .form-section {
            margin-bottom: 2rem;
            border: 1px solid #ddd;
            padding: 1.5rem;
        }

        .performer-profile-card .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .performer-profile-card .instruction {
            font-weight: 400;
            font-style: italic;
            color: #666;
            font-size: 0.9rem;
        }

        .performer-profile-card .form-value {
            padding: 0.5rem;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            color: #333;
            min-height: 36px;
            display: flex;
            align-items: center;
        }

        .performer-profile-card .photo-section {
            float: right;
            margin-left: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .performer-profile-card .photo-placeholder {
            width: 120px;
            height: 120px;
            border: 2px solid #333;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            color: #666;
            text-align: center;
            background: #f8f9fa;
            overflow: hidden;
        }

        .performer-profile-card .photo-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .performer-profile-card .form-grid {
            display: grid;
            gap: 1rem;
        }

        .performer-profile-card .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
            margin-bottom: 0.5rem;
        }

        .performer-profile-card .form-group label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
            color: #333;
        }

        .performer-profile-card .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .performer-profile-card .form-group.half {
            flex: 1;
        }

        .performer-profile-card .form-group.quarter {
            flex: 0.5;
        }

        .performer-profile-card .participation-table,
        .performer-profile-card .competition-table,
        .performer-profile-card .affiliation-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .performer-profile-card .participation-table th,
        .performer-profile-card .competition-table th,
        .performer-profile-card .affiliation-table th,
        .performer-profile-card .participation-table td,
        .performer-profile-card .competition-table td,
        .performer-profile-card .affiliation-table td {
            border: 1px solid #333;
            padding: 0.75rem 0.5rem;
            text-align: left;
        }

        .performer-profile-card .participation-table th,
        .performer-profile-card .competition-table th,
        .performer-profile-card .affiliation-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .performer-profile-card .sub-text {
            font-weight: 400;
            font-style: italic;
            font-size: 0.75rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .events-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .record-number {
            font-size: 3rem;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 0.5rem;
        }

        .record-label {
            font-size: 1rem;
            color: #666;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .record-sublabel {
            font-size: 0.875rem;
            color: #888;
        }

        .overview-right {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .record-count-card h3 {
            color: #666;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .record-content {
            height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
            color: #888;
        }

        .record-content p {
            margin-bottom: 0.5rem;
        }

        .table-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            background: #dc2626;
            color: white;
        }

        .table-header-row {
            display: grid;
            grid-template-columns: 120px 1fr 200px 250px 200px;
            padding: 1rem;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .header-col {
            padding: 0 0.5rem;
            text-align: center;
        }

        .table-row > div:nth-child(3) {
            text-align: center;
        }

        .table-body {
            min-height: 200px;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            text-align: center;
            color: #888;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state small {
            color: #aaa;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .pagination-info {
            color: #666;
            font-size: 0.875rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: white;
            color: #666;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn:not(:disabled):hover {
            background: #f8f9fa;
            border-color: #dc2626;
        }

        .pagination-number {
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination-number.active {
            background: #dc2626;
            color: white;
        }

        /* Inventory Styles */
        .inventory-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .inventory-table-container {
            overflow: hidden;
        }

        .inventory-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            width: 100%;
            max-width: 100%;
            margin-top: 1.5rem;
        }

        #costume-inventory {
            display: none;
        }

        #costume-inventory.active {
            display: block;
            position: relative;
        }
        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                min-height: auto;
                position: relative;
                top: 0;
                left: 0;
                overflow-x: auto;
            }
            
            .main-content {
                margin-left: 0;
            }

            .nav-menu {
                display: flex;
                overflow-x: auto;
                padding: 1rem;
            }

            .nav-item {
                flex-shrink: 0;
                margin: 0 0.25rem;
            }

            .nav-link {
                padding: 0.75rem 1rem;
                border-left: none;
                border-bottom: 3px solid transparent;
                border-radius: 8px;
                white-space: nowrap;
            }

            .nav-link:hover,
            .nav-link.active {
                border-left: none;
                border-bottom-color: #dc2626;
            }

            .dashboard-cards {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            /* Inventory grid stacks on mobile */
            .inventory-grid {
                grid-template-columns: 1fr !important;
            }

            /* Inventory table headers adjust for mobile */
            .table-header-row {
                grid-template-columns: minmax(120px, 1fr) 60px 80px 80px minmax(120px, 1fr) !important;
                font-size: 0.75rem !important;
            }

            .header-col {
                padding: 0 0.25rem !important;
            }

            /* Mobile header improvements */
            .header {
                flex-direction: column;
                align-items: stretch;
                padding: 1rem;
                gap: 0.75rem;
            }

            .header-left {
                justify-content: flex-start;
                align-items: center;
                gap: 0.75rem;
            }

            .header-title {
                font-size: 1.1rem;
                margin: 0;
            }

            .header-right {
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
            }

            .user-info {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                background: #f8f9fa;
                padding: 0.5rem 0.75rem;
                border-radius: 25px;
                color: #333;
                font-size: 0.85rem;
                flex: 1;
                margin-right: 0.75rem;
            }

            .logout-btn {
                background: #dc2626;
                color: white;
                border: none;
                padding: 0.5rem 1rem;
                border-radius: 6px;
                font-size: 0.85rem;
                cursor: pointer;
                white-space: nowrap;
            }

            /* Mobile table improvements */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table {
                min-width: 600px;
                font-size: 0.85rem;
            }

            th, td {
                padding: 0.5rem !important;
                white-space: nowrap;
            }

            /* Mobile modal improvements */
            .modal-content {
                max-width: 95% !important;
                max-height: 90vh;
                margin: 1rem;
                overflow-y: auto;
            }

            .modal-header {
                padding: 1rem !important;
                flex-wrap: wrap;
            }

            .modal-header h2 {
                font-size: 1.25rem;
                margin-bottom: 0.5rem;
            }

            .modal-body {
                padding: 1rem !important;
            }

            /* Mobile form improvements */
            #borrowRequestsFilters,
            #returnsFilters {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 1rem !important;
            }

            #borrowRequestsFilters > div,
            #returnsFilters > div {
                width: 100%;
            }

            #statusRequestFilter,
            #statusReturnFilter,
            #requestSearchInput,
            #searchReturnFilter {
                width: 100% !important;
                min-width: auto !important;
            }

            /* Mobile button improvements */
            .add-btn,
            .btn,
            button {
                width: 100%;
                margin-bottom: 0.5rem;
                font-size: 0.9rem;
            }

            /* Mobile stats cards */
            .student-overview {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }

            .overview-right {
                order: -1;
            }

            .stats-cards {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.75rem;
            }

            .stat-card {
                padding: 1rem !important;
            }

            .stat-number {
                font-size: 1.5rem !important;
            }

            /* Mobile charts */
            .chart-placeholder {
                height: 200px !important;
            }

            canvas {
                max-width: 100% !important;
                height: auto !important;
            }
        }

        /* Tablet styles */
        @media (min-width: 769px) and (max-width: 1024px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .stats-cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr 1fr;
            }

            .modal-content {
                max-width: 90%;
            }

            table {
                font-size: 0.9rem;
            }

            /* Inventory tables on tablet */
            .table-header-row {
                grid-template-columns: 2fr 70px 90px 90px 1fr !important;
                font-size: 0.8rem !important;
            }
        }

        /* Small mobile devices */
        @media (max-width: 480px) {
            .container {
                padding: 0.5rem !important;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .stats-cards {
                grid-template-columns: 1fr !important;
            }

            .dashboard-cards {
                grid-template-columns: 1fr !important;
            }

            .stat-card {
                text-align: center;
            }

            .modal-content {
                margin: 0.5rem;
                max-height: 95vh;
            }

            .modal-header {
                padding: 0.75rem !important;
            }

            .modal-body {
                padding: 0.75rem !important;
            }

            /* Very small button adjustments */
            .close {
                font-size: 1.5rem !important;
                padding: 0.25rem !important;
            }

            /* Mobile navigation improvements */
            .nav-menu {
                gap: 0.25rem;
                padding: 0.5rem;
            }

            .nav-link {
                padding: 0.5rem 0.75rem !important;
                font-size: 0.85rem;
            }
        }

        /* Touch improvements for all screen sizes */
        @media (pointer: coarse) {
            /* Larger touch targets for touch devices */
            .nav-link,
            .add-btn,
            .btn,
            button,
            .action-btn {
                min-height: 44px;
                min-width: 44px;
                padding: 0.75rem 1rem;
            }

            .close {
                min-height: 44px;
                min-width: 44px;
                padding: 0.5rem;
            }

            /* Larger select and input fields */
            select,
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="date"],
            textarea {
                min-height: 44px;
                padding: 0.75rem;
                font-size: 16px; /* Prevents zoom on iOS */
            }

            /* Better spacing for touch */
            .sidebar .nav-item {
                margin-bottom: 2px;
            }

            .table-container {
                padding-bottom: 1rem;
            }
        }

        /* High DPI/Retina display improvements */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .modal-content {
                border-radius: 12px;
            }

            .btn,
            .add-btn,
            button {
                border-radius: 8px;
            }

            /* Sharper text rendering */
            body {
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
        }

        /* Dark mode support (if system prefers dark) */
        @media (prefers-color-scheme: dark) {
            /* Optional: You can add dark mode styles here if needed */
        }

        /* Reduced motion for accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-height: 90vh;
            overflow: hidden;
        }

        .applications-modal {
            width: 95%;
            max-width: 1200px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
            font-size: 1.5rem;
        }

        .close {
            color: #666;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            color: #ff5a5a;
            background-color: #f0f0f0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Search input styling */
        #applicationSearchInput:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }

        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-size: 1.1rem;
        }

        .applications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .application-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
        }

        .application-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background-color: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        .student-info h3 {
            margin: 0;
            color: #333;
            font-size: 1.2rem;
        }

        .student-details {
            margin: 0.25rem 0 0 0;
            color: #666;
            font-size: 0.9rem;
        }

        .application-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .details-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }

        .details-btn:hover {
            background: #5a6268;
        }

        .approve-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }

        .approve-btn:hover {
            background: #218838;
        }

        .deny-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }

        .deny-btn:hover {
            background: #c82333;
        }

        .application-details {
            padding: 1.5rem;
            border-top: 1px solid #eee;
            background: white;
            border-radius: 0 0 8px 8px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .detail-section h4 {
            margin: 0 0 1rem 0;
            color: #333;
            font-size: 1.1rem;
            border-bottom: 2px solid #ff5a5a;
            padding-bottom: 0.5rem;
        }

        .detail-section p {
            margin: 0.5rem 0;
            color: #555;
            line-height: 1.4;
        }

        .detail-section strong {
            color: #333;
            font-weight: 600;
        }

        /* Profile photo styling in application details */
        .detail-section img {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .detail-section img:hover {
            transform: scale(1.05);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-size: 1.1rem;
        }

        .error {
            color: #dc3545;
            text-align: center;
            padding: 1rem;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/OCA Logo.png" alt="BatStateU Logo" class="logo">
            <h1 class="header-title">Culture and Arts - Dashboard</h1>
        </div>
        <div class="header-right">
            <?php 
            $first_name = explode(' ', $_SESSION['user_name'])[0];
            $role_display = strtoupper($user_role);
            ?>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-greeting">
                <h3>
                    <span class="greeting-hi">Hi,</span>
                    <span class="greeting-name"><?= htmlspecialchars($first_name) ?></span>
                </h3>
                <p><?= htmlspecialchars($role_display) ?><?php if (!$isDirector): ?> - <?= htmlspecialchars($display_campus) ?><?php endif; ?></p>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="#" class="nav-link active" data-section="dashboard">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="announcements.php" class="nav-link">
                            Announcements
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="student-profiles">
                            Student Artist Profiles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="events-trainings">
                            Events & Trainings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="reports-analytics">
                            Reports & Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="inventory.php" class="nav-link">
                            Inventory
                        </a>
                    </li>
                    <?php if ($canManage): ?>
                    <li class="nav-item">
                        <a href="archives.php" class="nav-link">
                            Archives
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Dashboard Section -->
            <section class="content-section active" id="dashboard">
                <div class="page-header">
                    <h1 class="page-title">Dashboard Overview</h1>
                </div>

                <!-- Dashboard Cards -->
                <div class="dashboard-cards">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-title">Student Artist</div>
                        </div>
                        <div class="card-number"><?= $student_count ?></div>
                        <div class="card-subtitle">Active student performers</div>
                    </div>

                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-title">Cultural Groups</div>
                        </div>
                        <div class="card-number">12</div>
                        <div class="card-subtitle">Registered cultural groups</div>
                    </div>

                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-title">Events Scheduled</div>
                        </div>
                        <div class="card-number"><?= $scheduled_events ?></div>
                        <div class="card-subtitle">Upcoming events</div>
                    </div>

                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-title">Worn Out Items</div>
                        </div>
                        <div class="card-number"><?= $worn_costumes ?></div>
                        <div class="card-subtitle">Need replacement</div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Student Artist Overview</h3>
                        </div>
                        <div class="panel-content">
                            No data available
                        </div>
                    </div>

                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Upcoming Events & Trainings</h3>
                        </div>
                        <div class="panel-content">
                            No upcoming events scheduled
                        </div>
                    </div>

                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Inventory Status</h3>
                        </div>
                        <div class="panel-content">
                            No inventory data available
                        </div>
                    </div>
                </div>
            </section>

            <!-- Student Artist Profiles Section -->
            <section class="content-section" id="student-profiles">
                <div class="page-header">
                    <h1 class="page-title">Student Artist Profiles</h1>
                    <?php if ($canManage): ?>
                    <button class="add-btn" onclick="openApplicationsModal()">
                        <span>+</span>
                        View Applications
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Search and Filter Section -->
                <div class="search-section">
                    <form method="GET" style="display: flex; gap: 1rem; align-items: end;">
                        <input type="hidden" name="section" value="student-profiles">
                        <div class="search-container">
                            <label for="studentSearch">Search Students</label>
                            <input type="text" id="studentSearch" name="search" placeholder="Name, Email, or SR Code" 
                                   class="search-input" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <button type="submit" style="background: #dc2626; color: white; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; height: 36px; box-sizing: border-box; font-size: 14px;">Search</button>
                        <?php if (!empty($search)): ?>
                            <a href="?section=student-profiles<?= !empty($campus_filter) ? '&campus_filter=' . urlencode($campus_filter) : '' ?>" style="background: #6c757d; color: white; padding: 0.5rem 1rem; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; box-sizing: border-box; height: 36px; font-size: 14px;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Dashboard Overview -->
                <div class="student-overview" style="width: 100%; max-width: 100%;">
                    <div class="overview-left">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; color: #666;">Distribution of Student Artists</h3>
                            <?php if ($canViewAll): ?>
                                <select id="campusFilterDropdown" onchange="filterByCampus()" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; cursor: pointer;">
                                    <option value="Pablo Borbon" <?= $campus_filter === 'Pablo Borbon' ? 'selected' : '' ?>>Pablo Borbon</option>
                                    <option value="Alangilan" <?= $campus_filter === 'Alangilan' ? 'selected' : '' ?>>Alangilan</option>
                                    <option value="Lipa" <?= $campus_filter === 'Lipa' ? 'selected' : '' ?>>Lipa</option>
                                    <option value="ARASOF Nasugbu" <?= $campus_filter === 'ARASOF Nasugbu' ? 'selected' : '' ?>>ARASOF Nasugbu</option>
                                    <option value="JPLPC Malvar" <?= $campus_filter === 'JPLPC Malvar' ? 'selected' : '' ?>>JPLPC Malvar</option>
                                </select>
                            <?php else: ?>
                                <select id="campusFilterDropdown" disabled style="padding: 0.5rem; border: 1px solid #eee; border-radius: 4px; font-size: 0.9rem; cursor: default; background: #f8f9fa; color: #333;">
                                    <option value="<?= htmlspecialchars($campus_filter) ?>"><?= htmlspecialchars($campus_filter) ?></option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div id="campusDistributionChart" class="campus-distribution">
                            <div class="bar-chart-container" id="barChart">
                                <!-- Bar chart will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="overview-right">
                        <div class="record-count-card">
                            <h3>Cultural Groups</h3>
                            <div class="record-count-section" id="culturalGroupCounts">
                                <div class="record-number"><?= $cultural_groups_count ?></div>
                                <div class="record-label">student artists</div>
                                <div class="record-sublabel">Loading group distribution...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Student Profiles Table -->
                <div class="table-section">
                    <div class="table-header">
                        <div class="table-header-row">
                            <div class="header-col">SR-CODE</div>
                            <div class="header-col">FULL NAME</div>
                            <div class="header-col">CULTURAL GROUP</div>
                            <div class="header-col">EMAIL</div>
                            <div class="header-col">ACTIONS</div>
                        </div>
                    </div>
                    
                    <div class="table-body">
                        <?php if (empty($students)): ?>
                            <div class="empty-state">
                                <?php if (!empty($search)): ?>
                                    <p>No student artists found matching "<?= htmlspecialchars($search) ?>"</p>
                                    <small>Try a different search term or clear the search.</small>
                                <?php else: ?>
                                    <p>No student artist profiles found.</p>
                                    <small>Click "View Applications" to approve new student artists.</small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <div class="table-row" style="display: grid; grid-template-columns: 120px 1fr 200px 250px 200px; padding: 1rem; border-bottom: 1px solid #e0e0e0; align-items: center;">
                                    <div style="padding: 0 0.5rem; font-weight: 600; color: #dc2626;">
                                        <?= htmlspecialchars($student['sr_code']) ?>
                                    </div>
                                    <div style="padding: 0 0.5rem;">
                                        <?= htmlspecialchars(trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'])) ?>
                                    </div>
                                    <div style="padding: 0 0.5rem;">
                                        <?php if (!empty($student['cultural_group'])): ?>
                                            <span style="background: #17a2b8; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                <?= htmlspecialchars($student['cultural_group']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #f0f0f0; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; color: #666;">
                                                Not Assigned
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="padding: 0 0.5rem; font-size: 0.9rem;">
                                        <?= htmlspecialchars($student['email']) ?>
                                    </div>
                                    <div style="padding: 0 0.5rem; display: flex; gap: 0.5rem;">
                                        <button onclick="viewStudentProfile(<?= $student['id'] ?>)" 
                                                style="background: #6c757d; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                            View
                                        </button>
                                        <button onclick="exportStudentProfilePDF(<?= $student['id'] ?>)" 
                                                style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                            📄 Export
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <span class="pagination-info">
                            Showing <?= empty($students) ? 0 : (($current_page - 1) * $students_per_page) + 1 ?> to 
                            <?= min($current_page * $students_per_page, $total_students) ?> of <?= $total_students ?> entries
                        </span>
                        <div class="pagination-controls">
                            <?php if ($current_page > 1): ?>
                                <a href="?section=student-profiles&page=<?= $current_page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($campus_filter) ? '&campus_filter=' . urlencode($campus_filter) : '' ?>" 
                                   class="pagination-btn">Previous</a>
                            <?php else: ?>
                                <button class="pagination-btn" disabled>Previous</button>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == $current_page): ?>
                                    <span class="pagination-number active"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?section=student-profiles&page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($campus_filter) ? '&campus_filter=' . urlencode($campus_filter) : '' ?>" 
                                       class="pagination-number" style="text-decoration: none; color: #666;"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?section=student-profiles&page=<?= $current_page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($campus_filter) ? '&campus_filter=' . urlencode($campus_filter) : '' ?>" 
                                   class="pagination-btn">Next</a>
                            <?php else: ?>
                                <button class="pagination-btn" disabled>Next</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Events & Trainings Section -->
            <section class="content-section" id="events-trainings">
                <div class="page-header">
                    <h1 class="page-title">Events & Trainings</h1>
                </div>

                <!-- Main Content Grid -->
                <div class="events-grid">
                    <?php if ($canManageEvents): ?>
                    <!-- Left Side - Input New Event -->
                    <div class="events-left">
                        <div class="input-panel">
                            <div class="panel-header-event">
                                <h3 class="panel-title-event">Input New Event</h3>
                            </div>
                            <form id="eventForm" class="event-form">
                                <div class="form-group">
                                    <label for="eventTitle">Event Title*</label>
                                    <input type="text" id="eventTitle" name="title" placeholder="Enter event title" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="eventDescription">Description*</label>
                                    <textarea id="eventDescription" name="description" placeholder="Enter event description" required></textarea>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="startDate">Start Date*</label>
                                        <input type="date" id="startDate" name="start_date" required min="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="endDate">End Date*</label>
                                        <input type="date" id="endDate" name="end_date" required min="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="eventLocation">Location*</label>
                                    <input type="text" id="eventLocation" name="location" placeholder="Enter event location" required>
                                </div>

                                <div class="form-group">
                                    <label for="municipality">Campus</label>
                                    <select id="municipality" name="municipality">
                                        <option value="Pablo Borbon">Pablo Borbon</option>
                                        <option value="Alangilan">Alangilan</option>
                                        <option value="Lipa">Lipa</option>
                                        <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                                        <option value="JPLPC Malvar">JPLPC Malvar</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="culturalGroups">Cultural Group(s) Concerned</label>
                                    <div class="multi-select-container">
                                        <div class="multi-select-display" id="culturalGroupsDisplay" onclick="toggleCulturalGroupsDropdown()">
                                            <span class="placeholder">Select cultural groups...</span>
                                            <span class="dropdown-arrow">▼</span>
                                        </div>
                                        <div class="multi-select-dropdown" id="culturalGroupsDropdown" style="display: none;">
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Dulaang Batangan">
                                                <span>Dulaang Batangan</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="BatStateU Dance Company">
                                                <span>BatStateU Dance Company</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Diwayanis Dance Theatre">
                                                <span>Diwayanis Dance Theatre</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="BatStateU Band">
                                                <span>BatStateU Band</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Indak Yaman Dance Varsity">
                                                <span>Indak Yaman Dance Varsity</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Ritmo Voice">
                                                <span>Ritmo Voice</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Sandugo Dance Group">
                                                <span>Sandugo Dance Group</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Areglo Band">
                                                <span>Areglo Band</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Teatro Aliwana">
                                                <span>Teatro Aliwana</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="The Levites">
                                                <span>The Levites</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Melophiles">
                                                <span>Melophiles</span>
                                            </label>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="cultural_groups[]" value="Sindayog">
                                                <span>Sindayog</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="eventCategory">Category*</label>
                                    <select id="eventCategory" name="category" required>
                                        <option value="">Select category</option>
                                        <option value="Training">Training</option>
                                        <option value="Performance">Performance</option>
                                        <option value="Competition">Competition</option>
                                        <option value="Workshop">Workshop</option>
                                        <option value="Cultural Event">Cultural Event</option>
                                        <option value="Festival">Festival</option>
                                    </select>
                                </div>

                                <div class="form-buttons" style="display: flex; gap: 0.5rem;">
                                    <button type="submit" class="save-event-btn">
                                        Save Event
                                    </button>
                                    <button type="button" class="cancel-event-btn" onclick="cancelEdit()" style="background: #6c757d; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: none;">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Right Side - Upcoming Events -->
                    <div class="events-right">
                        <div class="upcoming-panel">
                            <div class="panel-header-upcoming">
                                <h3 class="panel-title-upcoming">Upcoming Events</h3>
                            </div>
                            <div class="events-list" id="eventsList">
                                <!-- Events will be loaded here -->
                                <div class="empty-events">
                                    <p>No upcoming events scheduled</p>
                                    <?php if ($canManageEvents): ?>
                                    <small>Add a new event to get started</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="events-footer">
                                <a href="#" class="view-all-link" onclick="viewAllEvents()">View All Events →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Reports & Analytics Section -->
            <section class="content-section" id="reports-analytics">
                <div class="page-header">
                    <h1 class="page-title">Reports & Analytics</h1>
                </div>

                <!-- Event Participation Table -->
                <div class="content-panel">
                    <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                        <h3 class="panel-title" style="margin: 0;">Event Participation</h3>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <input type="text" id="participationSearchInput" placeholder="Search events by title, category, or location..." 
                                   style="padding: 0.5rem 0.75rem; border: 1px solid #ddd; border-radius: 20px; min-width: 260px; box-sizing: border-box;" 
                                   oninput="filterParticipationEvents()">
                            <button onclick="clearParticipationSearch()" style="background: #dc2626; color: white; border: none; padding: 0.45rem 0.75rem; border-radius: 6px; cursor: pointer;">Clear</button>
                        </div>
                    </div>
                    <div class="panel-content" id="eventParticipationContent">
                        <div class="loading-state" id="participationLoading">
                            <p>Loading event participation data...</p>
                        </div>
                        <div class="participation-table-container" id="participationTableContainer" style="display: none; max-height: 450px; overflow-y: auto;">
                            <!-- Event participation table will be loaded here - max 5 rows visible -->
                        </div>
                    </div>
                </div>

                <!-- Event Participation Chart -->
                <div class="content-panel" id="participationChartPanel" style="display: none; margin-top: 2rem;">
                    <div class="panel-header">
                        <h3 class="panel-title">Event Participation Chart</h3>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <button id="allEventsBtn" class="chart-control-btn active" style="padding: 0.5rem 1rem; border: 1px solid #dc2626; border-radius: 4px; background: #dc2626; color: white; cursor: pointer; font-weight: 600;">
                                All Events
                            </button>
                            <button id="individualEventBtn" class="chart-control-btn" style="padding: 0.5rem 1rem; border: 1px solid #ddd; border-radius: 4px; background: white; color: #333; cursor: pointer; font-weight: 600;">
                                Individual Events
                            </button>
                            <span id="selectedEventDisplay" style="margin-left: 1rem; color: #666; font-style: italic; display: none;">
                                No event selected
                            </span>
                        </div>
                    </div>
                    <div class="panel-content">
                        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <canvas id="participationChart" width="800" height="400" style="max-width: 100%; height: auto;"></canvas>
                        </div>
                        <div id="chartLegendContainer" style="margin-top: 1rem; display: flex; justify-content: center; flex-wrap: wrap; gap: 1rem;">
                            <!-- Legend will be populated here -->
                        </div>
                        <!-- Descriptive Analytics Section -->
                        <div id="chartAnalyticsContainer" style="margin-top: 2rem; padding: 1.5rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc2626;">
                            <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">📊 Analytics & Insights</h4>
                            <div id="chartAnalyticsContent">
                                <!-- Analytics will be populated here -->
                                <p style="color: #666; margin: 0;">Select a chart view to see detailed analytics</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Event Selection Modal -->
                <div id="eventSelectionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
                    <div style="background: white; border-radius: 8px; max-width: 600px; width: 90%; max-height: 70vh; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: #333; font-size: 1.2rem;">Select Event</h3>
                            <button onclick="closeEventSelectionModal()" style="background: none; border: none; font-size: 1.5rem; color: #666; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <!-- Search Bar -->
                        <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0;">
                            <div style="position: relative; max-width: 100%;">
                                <input 
                                    type="text" 
                                    id="eventSearchInput" 
                                    placeholder="Search events by title, category, or location..." 
                                    style="width: 100%; padding: 10px 40px 10px 15px; border: 1px solid #ddd; border-radius: 25px; font-size: 14px; outline: none; transition: border-color 0.3s; box-sizing: border-box;"
                                    oninput="filterEvents()"
                                >
                                <span style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; font-size: 16px;">🔍</span>
                            </div>
                        </div>
                        <div id="eventSelectionList" style="max-height: 250px; overflow-y: auto; padding: 1rem;">
                            <!-- Event list will be populated here - max 5 rows visible -->
                        </div>
                    </div>
                </div>

                <!-- Event Evaluation Selection Modal -->
                <div id="eventEvaluationSelectionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
                    <div style="background: white; border-radius: 8px; max-width: 600px; width: 90%; max-height: 70vh; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: #333; font-size: 1.2rem;">Select Event for Evaluation</h3>
                            <button onclick="closeEventEvaluationSelectionModal()" style="background: none; border: none; font-size: 1.5rem; color: #666; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <!-- Search Bar -->
                        <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0;">
                            <div style="position: relative; max-width: 100%;">
                                <input 
                                    type="text" 
                                    id="eventEvaluationSearchInput" 
                                    placeholder="Search events by title, category, or location..." 
                                    style="width: 100%; padding: 10px 40px 10px 15px; border: 1px solid #ddd; border-radius: 25px; font-size: 14px; outline: none; transition: border-color 0.3s; box-sizing: border-box;"
                                    oninput="filterEvaluationEvents()"
                                >
                                <span style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; font-size: 16px;">🔍</span>
                            </div>
                        </div>
                        <div id="eventEvaluationSelectionList" style="max-height: 350px; overflow-y: auto; padding: 1rem;">
                            <!-- Event list will be populated here -->
                        </div>
                    </div>
                </div>

                <!-- Event Evaluations Analytics Section -->
                <div class="content-panel" style="margin-top: 2rem;">
                    <div class="panel-header">
                        <h3 class="panel-title">Event Evaluations Analytics</h3>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <button id="allEvaluationsBtn" class="chart-control-btn active" style="padding: 0.5rem 1rem; border: 1px solid #dc2626; border-radius: 4px; background: #dc2626; color: white; cursor: pointer; font-weight: 600;">
                                All Evaluations
                            </button>
                            <button id="individualEventEvalBtn" class="chart-control-btn" style="padding: 0.5rem 1rem; border: 1px solid #ddd; border-radius: 4px; background: white; color: #333; cursor: pointer; font-weight: 600;">
                                Individual Event
                            </button>
                            <span id="selectedEvaluationEventDisplay" style="margin-left: 1rem; color: #dc2626; font-style: normal; display: none;">
                                No event selected
                            </span>
                        </div>
                    </div>
                    <div class="panel-content">
                        <div class="loading-state" id="evaluationAnalyticsLoading">
                            <p>Loading evaluation analytics...</p>
                        </div>
                        <div id="evaluationAnalyticsContent" style="display: none;">
                            <!-- Evaluation Statistics Cards -->
                            <div class="evaluation-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                                <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #28a745;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h4 style="margin: 0 0 0.5rem 0; color: #666; font-size: 0.9rem; font-weight: 500;">Total Evaluations</h4>
                                            <p id="totalEvaluations" style="margin: 0; font-size: 2rem; font-weight: 700; color: #28a745;">0</p>
                                        </div>
                                        <div style="font-size: 2rem; color: #28a745;">📝</div>
                                    </div>
                                </div>
                                <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #ffc107;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h4 style="margin: 0 0 0.5rem 0; color: #666; font-size: 0.9rem; font-weight: 500;">Average Rating</h4>
                                            <p id="averageRating" style="margin: 0; font-size: 2rem; font-weight: 700; color: #ffc107;">0.0</p>
                                        </div>
                                        <div style="font-size: 2rem; color: #ffc107;">⭐</div>
                                    </div>
                                </div>
                                <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #17a2b8;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h4 style="margin: 0 0 0.5rem 0; color: #666; font-size: 0.9rem; font-weight: 500;">Response Rate</h4>
                                            <p id="responseRate" style="margin: 0; font-size: 2rem; font-weight: 700; color: #17a2b8;">0%</p>
                                        </div>
                                        <div style="font-size: 2rem; color: #17a2b8;">📊</div>
                                    </div>
                                </div>
                                <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #6f42c1;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h4 style="margin: 0 0 0.5rem 0; color: #666; font-size: 0.9rem; font-weight: 500;">Satisfaction Score</h4>
                                            <p id="satisfactionScore" style="margin: 0; font-size: 2rem; font-weight: 700; color: #6f42c1;">0%</p>
                                        </div>
                                        <div style="font-size: 2rem; color: #6f42c1;">😊</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Charts Grid -->
                            <div style="display: flex; justify-content: center; margin-bottom: 2rem;">
                                <!-- Question Scores Chart -->
                                <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 800px; width: 100%;">
                                    <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; font-weight: 600; text-align: center;">Question Scores</h4>
                                    <canvas id="questionScoresChart" width="400" height="420" style="max-width: 100%; height: auto;"></canvas>
                                </div>
                            </div>

            <!-- Evaluation Trends Chart -->
            <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">Evaluation Trends Over Time</h4>
                <div style="position: relative; height: 400px; width: 100%;">
                    <canvas id="evaluationTrendsChart"></canvas>
                </div>
            </div>
            </div>                            <!-- Detailed Analytics -->
                            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #dc2626;">
                                <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">📈 Detailed Analytics & Insights</h4>
                                <div id="evaluationInsightsContent">
                                    <!-- Detailed insights will be populated here -->
                                    <p style="color: #666; margin: 0;">Loading detailed analytics...</p>
                                </div>
                            </div>

                            <!-- Comments Analysis -->
                            <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 2rem;">
                                <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">💬 Comments Analysis</h4>
                                <div id="commentsAnalysisContent">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                        <div>
                                            <h5 style="margin: 0 0 1rem 0; color: #666; font-size: 1rem;">Most Positive Comments</h5>
                                            <div id="positiveComments" style="max-height: 200px; overflow-y: auto;">
                                                <!-- Positive comments will be loaded here -->
                                            </div>
                                        </div>
                                        <div>
                                            <h5 style="margin: 0 0 1rem 0; color: #666; font-size: 1rem;">Areas for Improvement</h5>
                                            <div id="improvementComments" style="max-height: 200px; overflow-y: auto;">
                                                <!-- Improvement comments will be loaded here -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- Costume Inventory Section -->
            <section class="content-section" id="costume-inventory">
                <div class="page-header">
                    <h1 class="page-title">Inventory</h1>
                    <?php if ($canManage): ?>
                    <div style="display: flex; gap: 1rem;">
                        <button class="add-btn" onclick="openAddItemModal()">
                            <span>+</span>
                            Add Item
                        </button>
                        <button class="add-btn" onclick="openBorrowRequests()">
                            Borrow Requests
                        </button>
                        <button class="add-btn" onclick="openReturns()">
                            Returns
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Inventory Grid -->
                <div class="inventory-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; width: 100%; max-width: 100%;">
                    <!-- Costumes Table -->
                    <div class="inventory-left">
                        <div class="inventory-panel">
                            <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0;">
                                <h3 style="margin: 0; color: #333; font-size: 1.25rem; font-weight: 600;">Costumes</h3>
                            </div>
                            <div class="inventory-table-container">
                                <div class="table-section">
                                    <div class="table-header">
                                        <div class="table-header-row" style="grid-template-columns: 2fr 80px 100px 100px 1fr;">
                                            <div class="header-col">NAME</div>
                                            <div class="header-col">QTY</div>
                                            <div class="header-col">CONDITION</div>
                                            <div class="header-col">STATUS</div>
                                            <div class="header-col">BORROWER INFO</div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-body" id="costumesTableBody" style="max-height: 560px; overflow-y: auto;">
                                        <!-- Costumes will be loaded dynamically - max 10 rows visible -->
                                        <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                                            <p>No costumes found.</p>
                                            <small>Click "Add Costume" to get started.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Equipment Table -->
                    <div class="inventory-right">
                        <div class="inventory-panel">
                            <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0;">
                                <h3 style="margin: 0; color: #333; font-size: 1.25rem; font-weight: 600;">Equipment</h3>
                            </div>
                            <div class="inventory-table-container">
                                <div class="table-section">
                                    <div class="table-header">
                                        <div class="table-header-row" style="grid-template-columns: 2fr 80px 100px 100px 1fr;">
                                            <div class="header-col">NAME</div>
                                            <div class="header-col">QTY</div>
                                            <div class="header-col">CONDITION</div>
                                            <div class="header-col">STATUS</div>
                                            <div class="header-col">BORROWER INFO</div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-body" id="equipmentTableBody" style="max-height: 560px; overflow-y: auto;">
                                        <!-- Equipment will be loaded dynamically - max 10 rows visible -->
                                        <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                                            <p>No equipment found.</p>
                                            <small>Click "Add Costume" to get started.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Applications Modal -->
    <div id="applicationsModal" class="modal" style="display: none;">
        <div class="modal-content applications-modal" style="max-width: 1000px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
            <div class="modal-header" style="flex-shrink: 0;">
                <h2>Student Applications</h2>
                <span class="close" onclick="closeApplicationsModal()">&times;</span>
            </div>
            <div class="modal-body" style="flex: 1; overflow-y: auto; padding: 1.5rem;">
                <!-- Search Bar -->
                <div style="margin-bottom: 1.5rem;">
                    <div style="position: relative; max-width: 400px;">
                        <input 
                            type="text" 
                            id="applicationSearchInput" 
                            placeholder="Search by student name..." 
                            style="width: 100%; padding: 10px 40px 10px 15px; border: 1px solid #ddd; border-radius: 25px; font-size: 14px; outline: none; transition: border-color 0.3s;"
                            oninput="filterApplications()"
                        >
                        <span style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; font-size: 16px;">🔍</span>
                    </div>
                </div>
                
                <div id="applicationsLoading" style="display: none;">
                    <div class="loading-spinner">Loading applications...</div>
                </div>
                <div id="applicationsContent">
                    <!-- Applications will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Student Profile Modal -->
    <div id="studentProfileModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1400px; width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header">
                <h2>Student Information</h2>
                <span class="close" onclick="closeStudentModal()">&times;</span>
            </div>
            
            <!-- Tab Navigation -->
            <div style="border-bottom: 2px solid #e0e0e0; background: #f8f9fa; padding: 0 1.5rem;">
                <div style="display: flex; gap: 0;">
                    <button class="student-tab-btn active" data-tab="profile" onclick="switchStudentTab('profile')" style="padding: 1rem 2rem; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 3px solid #dc2626; color: #dc2626; transition: all 0.3s;">
                        Profile
                    </button>
                    <button class="student-tab-btn" data-tab="certificates" onclick="switchStudentTab('certificates')" style="padding: 1rem 2rem; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 3px solid transparent; color: #666; transition: all 0.3s;">
                        Certificates
                    </button>
                </div>
            </div>
            
            <div class="modal-body" style="overflow-y: auto; flex: 1; padding: 2rem;">
                <div id="studentLoading" style="display: none;">
                    <div class="loading-spinner">Loading student information...</div>
                </div>
                
                <!-- Profile Tab Content -->
                <div id="profileTab" class="student-tab-content" style="display: block;">
                    <div id="studentContent">
                        <!-- Student profile will be loaded here -->
                    </div>
                </div>
                
                <!-- Certificates Tab Content -->
                <div id="certificatesTab" class="student-tab-content" style="display: none;">
                    <div id="certificatesLoading" style="display: none; text-align: center; padding: 2rem; color: #666;">
                        Loading certificates...
                    </div>
                    <div id="certificatesContainer">
                        <!-- Certificates will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- All Events Modal -->
    <div id="allEventsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1200px; width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header">
                <h2>All Events</h2>
                <span class="close" onclick="closeAllEventsModal()">&times;</span>
            </div>
            <div class="modal-body" style="flex: 1; overflow-y: auto; max-height: calc(90vh - 120px);">
                <div id="eventsFilters" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                    <div>
                        <label for="statusFilter">Status:</label>
                        <select id="statusFilter" onchange="loadAllEvents()">
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <label for="categoryFilter">Category:</label>
                        <select id="categoryFilter" onchange="loadAllEvents()">
                            <option value="">All Categories</option>
                            <option value="Training">Training</option>
                            <option value="Performance">Performance</option>
                            <option value="Competition">Competition</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Cultural Event">Cultural Event</option>
                            <option value="Festival">Festival</option>
                        </select>
                    </div>
                    <?php if ($canViewAll): ?>
                    <div>
                        <label for="campusFilter">Campus:</label>
                        <select id="campusFilter" onchange="loadAllEvents()">
                            <option value="">All Campuses</option>
                            <option value="Pablo Borbon">Pablo Borbon</option>
                            <option value="Alangilan">Alangilan</option>
                            <option value="Lipa">Lipa</option>
                            <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                            <option value="JPLPC Malvar">JPLPC Malvar</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label for="monthFilter">Month:</label>
                        <input type="month" id="monthFilter" onchange="loadAllEvents()">
                    </div>
                </div>
                <div id="allEventsLoading" style="display: none;">
                    <div class="loading-spinner">Loading events...</div>
                </div>
                <div id="allEventsContent">
                    <!-- Events will be loaded here -->
                </div>
                <div id="eventsPagination" style="margin-top: 1rem; text-align: center;">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Borrow Requests Modal -->
    <div id="borrowRequestsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1000px; width: 95%;">
            <div class="modal-header">
                <h2>Student Borrow Requests</h2>
                <span class="close" onclick="closeBorrowRequestsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="borrowRequestsFilters" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                    <div>
                        <label for="statusRequestFilter" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333; font-size: 0.9rem;">Status:</label>
                        <select id="statusRequestFilter" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-width: 120px;" onchange="loadBorrowRequests()">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="">All Status</option>
                        </select>
                    </div>
                    <div>
                        <label for="requestSearchInput" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333; font-size: 0.9rem;">Search:</label>
                        <input type="text" id="requestSearchInput" placeholder="Search by name or email..." 
                               style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; width: 250px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;"
                               onkeyup="debounceSearch(loadBorrowRequests, 500)">
                    </div>
                    <button onclick="loadBorrowRequests()" style="padding: 0.75rem 1rem; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; cursor: pointer; height: fit-content;">
                        Refresh
                    </button>
                </div>
                <div id="borrowRequestsLoading" style="text-align: center; padding: 2rem;">
                    <p>Loading borrow requests...</p>
                </div>
                <div id="borrowRequestsContent" style="display: none;">
                    <div class="table-container">
                        <table id="borrowRequestsTable">
                            <thead>
                                <tr>
                                    <th>Student Info</th>
                                    <th>Items Requested</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="borrowRequestsTableBody">
                                <!-- Data will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="borrowRequestsPagination" style="margin-top: 1rem; text-align: center;">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Returns Modal -->
    <div id="returnsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1200px; width: 95%;">
            <div class="modal-header">
                <h2>Return Requests</h2>
                <span class="close" onclick="closeReturnsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="returnsFilters" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                    <div>
                        <label for="statusReturnFilter" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333; font-size: 0.9rem;">Status:</label>
                        <select id="statusReturnFilter" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-width: 150px;" onchange="loadReturnRequests()">
                            <option value="pending">Pending Returns</option>
                            <option value="completed">Completed Returns</option>
                            <option value="">All Status</option>
                        </select>
                    </div>
                    <div>
                        <label for="searchReturnFilter" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333; font-size: 0.9rem;">Search:</label>
                        <input type="text" id="searchReturnFilter" placeholder="Student name or item name..." 
                               onkeyup="debounceReturnSearch()" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; width: 250px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                    </div>
                    <button onclick="loadReturnRequests()" style="padding: 0.75rem 1rem; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; cursor: pointer; height: fit-content;">
                        Refresh
                    </button>
                </div>
                <div id="returnsLoading" style="text-align: center; padding: 2rem;">
                    <p>Loading return requests...</p>
                </div>
                <div id="returnsContent" style="display: none;">
                    <div class="table-container">
                        <table id="returnRequestsTable">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Item Name</th>
                                    <th>Request Date</th>
                                    <th>Condition Notes</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="returnRequestsTableBody">
                                <!-- Data will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="returnsPagination" style="margin-top: 1rem; text-align: center;">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div id="addItemModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px; width: 90%;">
            <div class="modal-header">
                <h2>Add Costume/Equipment</h2>
                <span class="close" onclick="closeAddItemModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addItemForm" style="display: flex; flex-direction: column; gap: 1rem;">
                    <div class="form-group">
                        <label for="itemName">Name*</label>
                        <input type="text" id="itemName" name="name" placeholder="Enter item name" required 
                               style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    </div>
                    
                    <div class="form-group">
                        <label for="itemCategory">Category*</label>
                        <select id="itemCategory" name="category" required 
                                style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                            <option value="">Select category</option>
                            <option value="costume">Costume</option>
                            <option value="equipment">Equipment</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemQuantity">Quantity*</label>
                        <input type="number" id="itemQuantity" name="quantity" placeholder="Enter quantity" required min="0" value="0"
                               style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    </div>
                    
                    <div class="form-group">
                        <label for="itemCondition">Condition*</label>
                        <select id="itemCondition" name="condition" required 
                                style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                            <option value="">Select condition</option>
                            <option value="good">Good</option>
                            <option value="worn-out">Worn-out</option>
                            <option value="bad">Bad</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="itemDescription">Description (Optional)</label>
                        <textarea id="itemDescription" name="description" placeholder="Enter item description" 
                                  style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit; min-height: 80px; resize: vertical;"></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" onclick="closeAddItemModal()" 
                                style="background: #6c757d; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; font-size: 1rem;">
                            Cancel
                        </button>
                        <button type="submit" 
                                style="background: #dc2626; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; font-size: 1rem;">
                            Save Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Item Selection Modal -->
    <div id="itemSelectionModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1200px; width: 95%;">
            <div class="modal-header">
                <h2>Select Items to Approve</h2>
            </div>
            <div class="modal-body">
                <!-- Search bar -->
                <div style="margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: end;">
                    <div>
                        <label for="itemSearchInput">Search Items:</label>
                        <input type="text" id="itemSearchInput" placeholder="Search by name..." 
                               style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; width: 300px;"
                               onkeyup="searchAvailableItems()">
                    </div>
                    <div>
                        <label for="categoryFilterSelect">Category:</label>
                        <select id="categoryFilterSelect" onchange="filterItemsByCategory()" 
                                style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="">All Items</option>
                            <option value="costume">Costumes</option>
                            <option value="equipment">Equipment</option>
                        </select>
                    </div>
                </div>

                <!-- Item selection grid -->
                <div class="inventory-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                    <!-- Costumes Table -->
                    <div class="inventory-left">
                        <div class="inventory-panel">
                            <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="margin: 0; color: #333; font-size: 1.25rem; font-weight: 600;">Available Costumes</h3>
                                <span id="selectedCostumesCount" style="color: #dc2626; font-weight: 600;">0 selected</span>
                            </div>
                            <div class="inventory-table-container" style="max-height: 400px; overflow-y: auto;">
                                <div class="table-section">
                                    <div class="table-header">
                                        <div class="table-header-row" style="grid-template-columns: 40px 1fr 120px 120px;">
                                            <div class="header-col">
                                                <input type="checkbox" id="selectAllCostumes" onchange="toggleSelectAllCostumes()">
                                            </div>
                                            <div class="header-col">NAME</div>
                                            <div class="header-col">CONDITION</div>
                                            <div class="header-col">STATUS</div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-body" id="availableCostumesBody">
                                        <!-- Available costumes will be loaded here -->
                                        <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                                            <p>Loading available costumes...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Equipment Table -->
                    <div class="inventory-right">
                        <div class="inventory-panel">
                            <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="margin: 0; color: #333; font-size: 1.25rem; font-weight: 600;">Available Equipment</h3>
                                <span id="selectedEquipmentCount" style="color: #dc2626; font-weight: 600;">0 selected</span>
                            </div>
                            <div class="inventory-table-container" style="max-height: 400px; overflow-y: auto;">
                                <div class="table-section">
                                    <div class="table-header">
                                        <div class="table-header-row" style="grid-template-columns: 40px 1fr 120px 120px;">
                                            <div class="header-col">
                                                <input type="checkbox" id="selectAllEquipment" onchange="toggleSelectAllEquipment()">
                                            </div>
                                            <div class="header-col">NAME</div>
                                            <div class="header-col">CONDITION</div>
                                            <div class="header-col">STATUS</div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-body" id="availableEquipmentBody">
                                        <!-- Available equipment will be loaded here -->
                                        <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                                            <p>Loading available equipment...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div style="display: flex; gap: 1rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #e0e0e0;">
                    <button type="button" onclick="closeItemSelectionModal()" 
                            style="background: #6c757d; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; font-size: 1rem;">
                        Cancel
                    </button>
                    <button type="button" onclick="approveWithSelectedItems()" 
                            style="background: #28a745; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; font-size: 1rem;" 
                            id="approveButton" disabled>
                        Approve Request (0 items)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Participants Modal -->
    <div id="eventParticipantsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 900px; width: 95%; display: flex; flex-direction: column; max-height: 90vh; overflow: hidden;">
            <div class="modal-header">
                <h2 id="participantsModalTitle">Event Participants</h2>
                <span class="close" onclick="closeParticipantsModal()">&times;</span>
            </div>
            <div class="modal-body" style="overflow-y: auto; flex: 1;">
                <div id="eventDetailsSection" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <div id="eventDetailsContent">
                        <!-- Event details will be loaded here -->
                    </div>
                </div>
                <div id="participantsLoading" style="text-align: center; padding: 2rem;">
                    <p>Loading participants...</p>
                </div>
                <div id="participantsContent" style="display: none;">
                    <div class="participants-summary" style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <h4 style="margin: 0; color: #333;">Participants List</h4>
                        <div style="display: flex; align-items: center; gap: 1rem; flex: 1; justify-content: flex-end;">
                            <input type="text" id="participantsSearchInput" placeholder="Search by name, email, or SR Code..." 
                                   style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; flex: 1; max-width: 300px; font-size: 0.9rem;">
                            <span id="participantsCount" style="background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; white-space: nowrap;">0 participants</span>
                        </div>
                    </div>
                    <div class="table-container" style="max-height: 400px; overflow-y: auto; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <table id="participantsTable" style="width: 100%; border-collapse: collapse; background: white;">
                            <thead style="background: #dc2626; color: white; position: sticky; top: 0; z-index: 10;">
                                <tr>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600;">Student Info</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600;">Cultural Group</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600;">Academic Info</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600;">Joined Date</th>
                                </tr>
                            </thead>
                            <tbody id="participantsTableBody">
                                <!-- Participants will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                    <div id="noParticipantsMessage" style="text-align: center; padding: 3rem; color: #666; display: none;">
                        <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No participants yet</p>
                        <small>Students who join this event will appear here</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variable to store applications data for filtering
        let allApplications = [];
        
        // User campus and permissions for campus filtering
        const userCampus = '<?php echo $user_campus ?? ''; ?>';
        const canViewAll = <?php echo $canViewAll ? 'true' : 'false'; ?>;
        const canManage = <?php echo $canManage ? 'true' : 'false'; ?>;
        const canManageEvents = <?php echo $canManageEvents ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const defaultCampusFilter = '<?php echo htmlspecialchars($campus_filter, ENT_QUOTES); ?>';

        // Utility function to format dates
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize campus field for event form
            initializeCampusField();
            
            const navLinks = document.querySelectorAll('.nav-link');
            const contentSections = document.querySelectorAll('.content-section');

            console.log('Navigation initialized with', navLinks.length, 'links and', contentSections.length, 'sections');

            // Check URL parameter for active section
            const urlParams = new URLSearchParams(window.location.search);
            const activeSection = urlParams.get('section') || 'dashboard';

            // Set active section based on URL parameter
            navLinks.forEach(l => l.classList.remove('active'));
            contentSections.forEach(s => s.classList.remove('active'));

            const activeLink = document.querySelector(`[data-section="${activeSection}"]`);
            const activeContentSection = document.getElementById(activeSection);

            if (activeLink && activeContentSection) {
                activeLink.classList.add('active');
                activeContentSection.classList.add('active');
                
                // Load upcoming events if Events & Trainings section is initially active
                if (activeSection === 'events-trainings') {
                    loadUpcomingEvents();
                }
                
                // Load event participation if Reports & Analytics section is initially active
                if (activeSection === 'reports-analytics') {
                    loadEventParticipation();
                    loadEvaluationAnalytics();
                }
                
                // Load inventory items if Costume Inventory section is initially active
                if (activeSection === 'costume-inventory') {
                    loadInventoryItems();
                }
            } else {
                // Default to dashboard if section not found
                const defaultLink = document.querySelector('[data-section="dashboard"]');
                const defaultSection = document.getElementById('dashboard');
                if (defaultLink) defaultLink.classList.add('active');
                if (defaultSection) defaultSection.classList.add('active');
            }
            
            // Check for modal parameter in URL and open the appropriate modal
            const modalParam = urlParams.get('modal');
            if (modalParam === 'borrow') {
                setTimeout(() => {
                    openBorrowRequests();
                }, 500);
            } else if (modalParam === 'returns') {
                setTimeout(() => {
                    openReturns();
                }, 500);
            }

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Skip navigation handling for external links (like inventory.php)
                    if (this.getAttribute('href') && this.getAttribute('href') !== '#') {
                        // Let the browser handle the navigation to external page
                        return;
                    }
                    
                    e.preventDefault();
                    
                    console.log('Navigation clicked:', this.dataset.section);
                    
                    // Remove active class from all links and sections
                    navLinks.forEach(l => l.classList.remove('active'));
                    contentSections.forEach(s => s.classList.remove('active'));
                    
                    // Add active class to clicked link
                    this.classList.add('active');
                    
                    // Show corresponding section
                    const sectionId = this.dataset.section;
                    const targetSection = document.getElementById(sectionId);
                    if (targetSection) {
                        targetSection.classList.add('active');
                        console.log('Activated section:', sectionId);
                        
                        // Load upcoming events when Events & Trainings section is activated
                        if (sectionId === 'events-trainings') {
                            loadUpcomingEvents();
                        }
                        
                        // Load event participation when Reports & Analytics section is activated
                        if (sectionId === 'reports-analytics') {
                            loadEventParticipation();
                            initializeEvaluationAnalytics();
                        }
                    } else {
                        console.error('Target section not found:', sectionId);
                    }

                    // Update URL without page reload
                    const newUrl = `${window.location.pathname}?section=${sectionId}`;
                    window.history.pushState({}, '', newUrl);
                });
            });

            // Expand button functionality
            const expandBtns = document.querySelectorAll('.expand-btn');
            expandBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    this.textContent = this.textContent === '+' ? '−' : '+';
                });
            });

            // Chart controls event listeners
            const allEventsBtn = document.getElementById('allEventsBtn');
            const individualEventBtn = document.getElementById('individualEventBtn');
            
            if (allEventsBtn) {
                allEventsBtn.addEventListener('click', function() {
                    setActiveChartButton('all');
                    if (typeof updateChart === 'function') {
                        updateChart();
                    }
                });
            }
            
            if (individualEventBtn) {
                individualEventBtn.addEventListener('click', function() {
                    if (typeof openEventSelectionModal === 'function') {
                        openEventSelectionModal();
                    }
                });
            }
        });

        // Utility function for debounced search
        let searchTimeout;
        function debounceSearch(func, delay) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => func(1), delay); // Reset to page 1 for new search
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../index.php';
            }
        }

        // Student Profiles functionality
        function openAddStudentModal() {
            alert('Add student modal functionality coming soon!');
        }

        // Global variable to store current student ID
        let currentViewingStudentId = null;

        function viewStudentProfile(studentId) {
            currentViewingStudentId = studentId; // Store globally for later use
            const modal = document.getElementById('studentProfileModal');
            modal.style.display = 'flex';
            preventBackgroundScroll();
            switchStudentTab('profile'); // Start with profile tab
            loadStudentProfile(studentId);
        }

        function exportStudentProfilePDF(studentId) {
            // Show loading indicator
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '⏳ Generating...';
            button.disabled = true;

            // Redirect to export URL
            window.location.href = 'export_student_profile_pdf.php?student_id=' + studentId;

            // Reset button after a delay
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 2000);
        }

        function switchStudentTab(tabName) {
            // Update tab buttons
            const tabButtons = document.querySelectorAll('.student-tab-btn');
            tabButtons.forEach(btn => {
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                    btn.style.borderBottom = '3px solid #dc2626';
                    btn.style.color = '#dc2626';
                } else {
                    btn.classList.remove('active');
                    btn.style.borderBottom = '3px solid transparent';
                    btn.style.color = '#666';
                }
            });

            // Update tab content
            const tabContents = document.querySelectorAll('.student-tab-content');
            tabContents.forEach(content => {
                content.style.display = 'none';
            });

            if (tabName === 'profile') {
                document.getElementById('profileTab').style.display = 'block';
            } else if (tabName === 'certificates') {
                document.getElementById('certificatesTab').style.display = 'block';
            }
        }

        // Student Profile Modal Functions
        function closeStudentModal() {
            const modal = document.getElementById('studentProfileModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        function loadStudentProfile(studentId) {
            const loadingDiv = document.getElementById('studentLoading');
            const contentDiv = document.getElementById('studentContent');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            fetch('get_student_profile.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ student_id: studentId })
            })
            .then(response => response.json())
            .then(data => {
                loadingDiv.style.display = 'none';
                contentDiv.style.display = 'block';
                
                if (data.success) {
                    displayStudentProfile(data.student);
                    // Load certificates in background for certificates tab
                    loadStudentCertificates(studentId);
                } else {
                    contentDiv.innerHTML = '<p class="error">Error loading student profile: ' + data.message + '</p>';
                }
            })
            .catch(error => {
                loadingDiv.style.display = 'none';
                contentDiv.style.display = 'block';
                contentDiv.innerHTML = '<p class="error">Error loading student profile: ' + error.message + '</p>';
            });
        }

        function loadStudentCertificates(studentId) {
            const certificatesLoading = document.getElementById('certificatesLoading');
            const certificatesContainer = document.getElementById('certificatesContainer');
            
            
            fetch('get_student_certificates.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ student_id: studentId })
            })
            .then(response => response.json())
            .then(data => {
                certificatesLoading.style.display = 'none';
                if (data.success) {
                    displayStudentCertificates(data.certificates || []);
                } else {
                    certificatesContainer.innerHTML = '<p style="color: #dc2626; text-align: center; padding: 2rem;">Error loading certificates: ' + data.message + '</p>';
                }
            })
            .catch(error => {
                certificatesLoading.style.display = 'none';
                certificatesContainer.innerHTML = '<p style="color: #dc2626; text-align: center; padding: 2rem;">Error loading certificates: ' + error.message + '</p>';
            });
        }

        function displayStudentCertificates(certificates) {
            const container = document.getElementById('certificatesContainer');
            
            if (certificates.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 3rem; background: #f8f9fa; border-radius: 8px; border: 2px dashed #ddd;">
                        <p style="color: #666; margin: 0; font-size: 1rem;">No certificates uploaded yet</p>
                        <small style="color: #999;">This student has not uploaded any certificates or achievements</small>
                    </div>
                `;
                return;
            }
            
            let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem;">';
            certificates.forEach(cert => {
                const fileExtension = cert.file_path.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png'].includes(fileExtension);
                
                html += `
                    <div style="border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)';" onmouseout="this.style.transform=''; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)';">
                        <div style="aspect-ratio: 16/9; background: #f8f9fa; display: flex; align-items: center; justify-content: center; overflow: hidden; border-bottom: 2px solid #dc2626;">
                            ${isImage ? 
                                `<img src="${cert.file_path}" alt="${cert.title}" style="width: 100%; height: 100%; object-fit: cover;">` :
                                `<div style="text-align: center; color: #666;">
                                    <div style="font-size: 3rem; margin-bottom: 0.5rem;">📄</div>
                                    <div style="font-weight: 600;">PDF Certificate</div>
                                </div>`
                            }
                        </div>
                        <div style="padding: 1.25rem;">
                            <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">${cert.title}</h4>
                            ${cert.description ? `<p style="margin: 0 0 0.75rem 0; color: #666; font-size: 0.9rem; line-height: 1.4;">${cert.description}</p>` : ''}
                            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 0.75rem; border-top: 1px solid #e0e0e0; margin-top: 0.75rem;">
                                <div style="color: #888; font-size: 0.85rem;">
                                    <div><strong>Date:</strong> ${new Date(cert.date_received).toLocaleDateString()}</div>
                                    <div style="margin-top: 0.25rem;"><strong>Uploaded:</strong> ${cert.uploaded_at}</div>
                                </div>
                            </div>
                            <button onclick="viewCertificate('${cert.file_path}')" style="width: 100%; margin-top: 1rem; padding: 0.75rem; border: 2px solid #dc2626; background: white; color: #dc2626; border-radius: 4px; cursor: pointer; font-size: 0.95rem; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.background='#dc2626'; this.style.color='white';" onmouseout="this.style.background='white'; this.style.color='#dc2626';">
                                View Certificate
                            </button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        function viewCertificate(filePath) {
            window.open(filePath, '_blank');
        }

        function displayStudentProfile(student) {
            const studentProfileContent = document.getElementById('studentContent');
            
            // Get cultural group value from the cultural_group field (text)
            let culturalGroupValue = student.cultural_group || 'Not assigned';
            const hasAssignedGroup = !!(student.cultural_group && String(student.cultural_group).trim());

            // Parse participation and affiliation data
            let participationRows = '';
            if (student.participation && student.participation.length > 0) {
                participationRows = student.participation.map(p => `
                    <tr>
                        <td>${p.date || ''}</td>
                        <td>${p.title || ''}</td>
                        <td>${p.level || ''}</td>
                        <td>${p.rank || ''}</td>
                    </tr>
                `).join('');
            } else {
                participationRows = '<tr><td colspan="4" style="text-align: center; color: #666;">No participation records</td></tr>';
            }

            let competitionRows = '';
            if (student.competition && student.competition.length > 0) {
                competitionRows = student.competition.map(c => `
                    <tr>
                        <td>${c.date || ''}</td>
                        <td>${c.title || ''}</td>
                        <td>${c.level || ''}</td>
                        <td>${c.rank || ''}</td>
                    </tr>
                `).join('');
            } else {
                competitionRows = '<tr><td colspan="4" style="text-align: center; color: #666;">No competition records</td></tr>';
            }

            let affiliationRows = '';
            if (student.affiliation && student.affiliation.length > 0) {
                affiliationRows = student.affiliation.map(a => `
                    <tr>
                        <td>${a.position || ''}</td>
                        <td>${a.organization || ''}</td>
                        <td>${a.year || ''}</td>
                    </tr>
                `).join('');
            } else {
                affiliationRows = '<tr><td colspan="3" style="text-align: center; color: #666;">No affiliation records</td></tr>';
            }
            
            // Build HTML matching student's profile form exactly
            const html = `
                <div class="performer-profile-card">
                    <div class="form-header">
                        <h1 class="form-title">PERFORMER'S PROFILE FORM</h1>
                    </div>
                    <div class="form-body">
                        <!-- Cultural Group / Type of Performance Section -->
                        <div class="form-section">
                            <h3 class="section-title">CULTURAL GROUP / TYPE OF PERFORMANCE</h3>
                            
                            ${!hasAssignedGroup && student.performance_type ? `
                            <div style="margin-bottom: 1rem; padding: 0.75rem; background: #e7f3ff; border-radius: 4px; border-left: 4px solid #2196F3;">
                                <span style="font-size: 0.85rem; color: #1565c0; font-weight: 500;">Applied For: </span>
                                <span style="font-weight: 600; color: #0d47a1;">${student.performance_type}</span>
                                <span style="font-size: 0.75rem; color: #1976d2; display: block; margin-top: 0.25rem; font-style: italic;">From application records</span>
                            </div>
                            ` : ''}
                            
                            ${canManage ? `
                            <div style="display: flex; gap: 1rem; align-items: flex-end;">
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Assign Group:</label>
                                    <select id="culturalGroupAssignSelect" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                                        <option value="">-- Select Cultural Group --</option>
                                    </select>
                                </div>
                                <button onclick="updateCulturalGroup()" style="background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; font-weight: 500; white-space: nowrap; height: fit-content;">
                                    Assign Group
                                </button>
                            </div>
                            ` : ''}
                            <div style="margin-top: 1rem; padding: 0.75rem; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #17a2b8;">
                                <span style="font-size: 0.85rem; color: #666;">Current Assignment: </span>
                                <span style="font-weight: 600; color: #333;">${culturalGroupValue}</span>
                            </div>
                        </div>

                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h3 class="section-title">PERSONAL INFORMATION</h3>
                            
                            <div class="photo-section">
                                <div class="photo-placeholder">
                                    ${student.profile_photo ? `<img src="../${student.profile_photo}?v=${Date.now()}" alt="Profile Photo">` : '<span>Passport Size Photo</span>'}
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-row">
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>First Name</label>
                                        <div class="form-value">${student.first_name || '&nbsp;'}</div>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>Middle Name</label>
                                        <div class="form-value">${student.middle_name || '&nbsp;'}</div>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>Last Name</label>
                                        <div class="form-value">${student.last_name || '&nbsp;'}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Permanent Address</label>
                                    <div class="form-value">${student.address || ''}</div>
                                </div>

                                <div class="form-group">
                                    <label>Present Address</label>
                                    <div class="form-value">${student.present_address || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Date of Birth</label>
                                        <div class="form-value">${student.date_of_birth || ''}</div>
                                    </div>
                                    <div class="form-group quarter">
                                        <label>Age</label>
                                        <div class="form-value">${student.age || ''}</div>
                                    </div>
                                    <div class="form-group quarter">
                                        <label>Gender</label>
                                        <div class="form-value">${student.gender || ''}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Place of Birth</label>
                                    <div class="form-value">${student.place_of_birth || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Email Address</label>
                                        <div class="form-value">${student.email || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Contact Number</label>
                                        <div class="form-value">${student.contact_number || ''}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Family Background Section -->
                        <div class="form-section">
                            <h3 class="section-title">FAMILY BACKGROUND</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Father's Name</label>
                                    <div class="form-value">${student.father_name || ''}</div>
                                </div>

                                <div class="form-group">
                                    <label>Mother's Name</label>
                                    <div class="form-value">${student.mother_name || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Guardian</label>
                                        <div class="form-value">${student.guardian || 'N/A'}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Guardian Contact</label>
                                        <div class="form-value">${student.guardian_contact || 'N/A'}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information Section -->
                        <div class="form-section">
                            <h3 class="section-title">ACADEMIC INFORMATION</h3>
                            <div class="form-grid">
                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Campus</label>
                                        <div class="form-value">${student.campus || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>College</label>
                                        <div class="form-value">${student.college || ''}</div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>SR-Code</label>
                                        <div class="form-value">${student.sr_code || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Year Level</label>
                                        <div class="form-value">${student.year_level || ''}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Program/Course</label>
                                    <div class="form-value">${student.program || ''}</div>
                                </div>

                                <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1rem;">
                                    <span style="font-size: 0.9rem; color: #333;">Number of Units Enrolled:</span>
                                    <div style="display: flex; gap: 1rem; align-items: center;">
                                        <span style="font-size: 0.85rem;">1st Semester:</span>
                                        <span style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa; min-width: 60px; text-align: center;">${student.first_semester_units || 0}</span>
                                        <span style="font-size: 0.85rem; margin-left: 1rem;">2nd Semester:</span>
                                        <span style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa; min-width: 60px; text-align: center;">${student.second_semester_units || 0}</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Instructors</label>
                                    <div class="form-value">${student.instructors || 'Not specified'}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Participation Section -->
                        <div class="form-section">
                            <h3 class="section-title">III. PARTICIPATION IN THE FIELD OF INTEREST / ACHIEVEMENTS <span class="instruction">(Last Five Years)</span></h3>
                            <table class="participation-table">
                                <thead>
                                    <tr>
                                        <th>DATE</th>
                                        <th>EVENT</th>
                                        <th>LEVEL <span class="sub-text">(Local, Regional, National, International)</span></th>
                                        <th>RANK (PLACE)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${participationRows}
                                </tbody>
                            </table>
                        </div>

                        <!-- Competition Section (NEW) -->
                        <div class="form-section">
                            <h3 class="section-title">IV. COMPETITION PARTICIPATED</h3>
                            <table class="competition-table">
                                <thead>
                                    <tr>
                                        <th>DATE</th>
                                        <th>EVENT</th>
                                        <th>LEVEL <span class="sub-text">(Local, Regional, National, International)</span></th>
                                        <th>RANK (PLACE)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${competitionRows}
                                </tbody>
                            </table>
                        </div>

                        <!-- Affiliation Section -->
                        <div class="form-section">
                            <h3 class="section-title">V. AFFILIATION TO ORGANIZATIONS</h3>
                            <table class="affiliation-table">
                                <thead>
                                    <tr>
                                        <th>POSITION</th>
                                        <th>NAME OF ORGANIZATION</th>
                                        <th>YEAR</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${affiliationRows}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            
            studentProfileContent.innerHTML = html;
            
            // Populate cultural groups dropdown with current selection
            if (canManage) {
                populateCulturalGroups(student.cultural_group);
            }
        }

        function getCurrentCampusFilter() {
            // Get current campus filter from URL parameters or use the PHP-set default
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('campus_filter') || defaultCampusFilter || 'Pablo Borbon';
        }

        function populateCulturalGroups(selectedGroup) {
            const select = document.getElementById('culturalGroupAssignSelect');
            if (!select) return;

            // Fetch cultural groups from server
            fetch('get_cultural_groups.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.cultural_groups) {
                        select.innerHTML = '<option value="">-- Select Cultural Group --</option>';
                        data.cultural_groups.forEach(group => {
                            const option = document.createElement('option');
                            option.value = group;
                            option.textContent = group;
                            if (group === selectedGroup) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        });
                    } else {
                        // Fallback to hardcoded list if fetch fails
                        const culturalGroups = [
                            'Dulaang Batangan',
                            'BatStateU Dance Company',
                            'Diwayanis Dance Theatre',
                            'BatStateU Band',
                            'Indak Yaman Dance Varsity',
                            'Ritmo Voice',
                            'Sandugo Dance Group',
                            'Areglo Band',
                            'Teatro Aliwana',
                            'The Levites',
                            'Melophiles',
                            'Sindayog'
                        ];
                        
                        select.innerHTML = '<option value="">-- Select Cultural Group --</option>';
                        culturalGroups.forEach(group => {
                            const option = document.createElement('option');
                            option.value = group;
                            option.textContent = group;
                            if (group === selectedGroup) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching cultural groups:', error);
                    // Fallback to hardcoded list
                    const culturalGroups = [
                        'Dulaang Batangan',
                        'BatStateU Dance Company',
                        'Diwayanis Dance Theatre',
                        'BatStateU Band',
                        'Indak Yaman Dance Varsity',
                        'Ritmo Voice',
                        'Sandugo Dance Group',
                        'Areglo Band',
                        'Teatro Aliwana',
                        'The Levites',
                        'Melophiles',
                        'Sindayog'
                    ];
                    
                    select.innerHTML = '<option value="">-- Select Cultural Group --</option>';
                    culturalGroups.forEach(group => {
                        const option = document.createElement('option');
                        option.value = group;
                        option.textContent = group;
                        if (group === selectedGroup) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                });
        }

        function updateCulturalGroup() {
            const select = document.getElementById('culturalGroupAssignSelect');
            const culturalGroup = select.value;
            
            if (!currentViewingStudentId) {
                alert('Error: No student selected');
                return;
            }
            
            fetch('update_cultural_group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    student_id: currentViewingStudentId, 
                    cultural_group: culturalGroup 
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Cultural group assignment updated successfully!');
                    // Force refresh cultural groups data with cache-busting
                    setTimeout(() => {
                        loadCulturalGroupDistribution('', getCurrentCampusFilter());
                    }, 500);
                    closeStudentModal();
                    // Refresh the student list to show updated assignments
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert('Error updating cultural group: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating cultural group: ' + error.message);
            });
        }

        // Modal scroll prevention functions
        function preventBackgroundScroll() {
            document.body.classList.add('modal-open');
        }

        function allowBackgroundScroll() {
            document.body.classList.remove('modal-open');
        }

        // Applications Modal Functions
        function openApplicationsModal() {
            const modal = document.getElementById('applicationsModal');
            modal.style.display = 'flex';
            preventBackgroundScroll();
            loadApplications();
        }

        function closeApplicationsModal() {
            const modal = document.getElementById('applicationsModal');
            const searchInput = document.getElementById('applicationSearchInput');
            
            modal.style.display = 'none';
            allowBackgroundScroll();
            // Clear search input when closing modal
            if (searchInput) {
                searchInput.value = '';
            }
        }

        function loadApplications() {
            const loadingDiv = document.getElementById('applicationsLoading');
            const contentDiv = document.getElementById('applicationsContent');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Get current campus filter
            const campusFilter = getCurrentCampusFilter();
            const url = `get_applications.php?campus_filter=${encodeURIComponent(campusFilter)}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayApplications(data.applications);
                    } else {
                        contentDiv.innerHTML = '<p class="error">Error loading applications: ' + data.message + '</p>';
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    contentDiv.innerHTML = '<p class="error">Error loading applications: ' + error.message + '</p>';
                });
        }

        function displayApplications(applications) {
            // Store applications globally for filtering
            allApplications = applications;
            
            const contentDiv = document.getElementById('applicationsContent');
            
            if (applications.length === 0) {
                contentDiv.innerHTML = '<p class="empty-state">No pending applications found.</p>';
                return;
            }
            
            renderApplicationsList(applications);
        }

        function renderApplicationsList(applications) {
            const contentDiv = document.getElementById('applicationsContent');
            
            if (applications.length === 0) {
                contentDiv.innerHTML = '<p class="empty-state">No applications match your search.</p>';
                return;
            }
            
            let html = '<div class="applications-list">';
            applications.forEach((app, index) => {
                html += `
                    <div class="application-item">
                        <div class="application-header">
                            <div class="student-info">
                                <h3>${app.full_name}</h3>
                                <p class="student-details">${app.sr_code} • ${app.email}</p>
                            </div>
                            <div class="application-actions">
                                <button class="details-btn" onclick="toggleApplicationDetails(${index})">
                                    <span id="toggle-${index}">▼</span> Details
                                </button>
                                <button class="approve-btn" onclick="updateApplicationStatus(${app.id}, 'approved')">
                                    Approve
                                </button>
                                <button class="deny-btn" onclick="updateApplicationStatus(${app.id}, 'rejected')">
                                    Reject
                                </button>
                            </div>
                        </div>
                        <div class="application-details" id="details-${index}" style="display: none;">
                            ${app.profile_photo ? `
                                <div class="detail-section" style="text-align: center; margin-bottom: 1rem;">
                                    <h4>Profile Photo</h4>
                                    <img src="../${app.profile_photo}" alt="Profile Photo" style="max-width: 150px; max-height: 150px; border: 2px solid #ddd; border-radius: 8px; object-fit: cover; display: block; margin: 0 auto;">
                                </div>
                            ` : `
                                <div class="detail-section" style="text-align: center; margin-bottom: 1rem; color: #999;">
                                    <p>No profile photo uploaded</p>
                                </div>
                            `}
                            <div class="details-grid">
                                <div class="detail-section">
                                    <h4>Personal Information</h4>
                                    <p><strong>Address:</strong> ${app.address}</p>
                                    <p><strong>Present Address:</strong> ${app.present_address || 'Same as above'}</p>
                                    <p><strong>Date of Birth:</strong> ${app.date_of_birth}</p>
                                    <p><strong>Age:</strong> ${app.age}</p>
                                    <p><strong>Gender:</strong> ${app.gender}</p>
                                    <p><strong>Place of Birth:</strong> ${app.place_of_birth}</p>
                                    <p><strong>Contact:</strong> ${app.contact_number}</p>
                                </div>
                                <div class="detail-section">
                                    <h4>Family Information</h4>
                                    <p><strong>Father:</strong> ${app.father_name}</p>
                                    <p><strong>Mother:</strong> ${app.mother_name}</p>
                                    <p><strong>Guardian:</strong> ${app.guardian || 'N/A'}</p>
                                    <p><strong>Guardian Contact:</strong> ${app.guardian_contact || 'N/A'}</p>
                                </div>
                                <div class="detail-section">
                                    <h4>Educational Information</h4>
                                    <p><strong>Campus:</strong> ${app.campus}</p>
                                    <p><strong>College:</strong> ${app.college}</p>
                                    <p><strong>Program:</strong> ${app.program}</p>
                                    <p><strong>Year Level:</strong> ${app.year_level}</p>
                                    ${app.first_semester_units && app.first_semester_units > 0 
                                        ? `<p><strong>Units:</strong> 1st Semester: ${app.first_semester_units}</p>` 
                                        : ''}
                                    ${app.second_semester_units && app.second_semester_units > 0 
                                        ? `<p><strong>Units:</strong> 2nd Semester: ${app.second_semester_units}</p>` 
                                        : ''}
                                </div>
                                <div class="detail-section">
                                    <h4>Performance Type</h4>
                                    <p>${app.performance_type}</p>
                                </div>
                                <div class="detail-section">
                                    <h4>Consent</h4>
                                    <p>${app.consent === 'yes' ? 'Agreed to data privacy terms' : 'Did not agree to data privacy terms'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            contentDiv.innerHTML = html;
        }

        function filterApplications() {
            const searchTerm = document.getElementById('applicationSearchInput').value.toLowerCase().trim();
            
            if (searchTerm === '') {
                renderApplicationsList(allApplications);
                return;
            }
            
            const filteredApplications = allApplications.filter(app => {
                const fullName = app.full_name.toLowerCase();
                const firstName = (app.first_name || '').toLowerCase();
                const middleName = (app.middle_name || '').toLowerCase();
                const lastName = (app.last_name || '').toLowerCase();
                const srCode = (app.sr_code || '').toLowerCase();
                const email = (app.email || '').toLowerCase();
                
                return fullName.includes(searchTerm) ||
                       firstName.includes(searchTerm) ||
                       middleName.includes(searchTerm) ||
                       lastName.includes(searchTerm) ||
                       srCode.includes(searchTerm) ||
                       email.includes(searchTerm);
            });
            
            renderApplicationsList(filteredApplications);
        }

        function toggleApplicationDetails(index) {
            const details = document.getElementById(`details-${index}`);
            const toggle = document.getElementById(`toggle-${index}`);
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                toggle.textContent = '▲';
            } else {
                details.style.display = 'none';
                toggle.textContent = '▼';
            }
        }

        function updateApplicationStatus(applicationId, status) {
            if (!confirm(`Are you sure you want to ${status} this application?`)) {
                return;
            }
            
            fetch('update_application_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    application_id: applicationId,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Application ${status} successfully!`);
                    loadApplications(); // Reload the applications list
                } else {
                    alert('Error updating application: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating application: ' + error.message);
            });
        }

        // Search functionality for student profiles
        function initializeStudentSearch() {
            const searchInput = document.getElementById('studentSearch');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.trim();
                    
                    // Debounce search to avoid too many requests
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        console.log('Searching for:', searchTerm);
                        // Reload charts with search filter
                        loadCampusDistribution(searchTerm);
                        loadCulturalGroupDistribution(searchTerm);
                    }, 300);
                });
            }
        }

        // Initialize search when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            initializeStudentSearch();
            // Use server-side campus filter to ensure non-Pablo users see their campus
            const campusFilter = '<?= htmlspecialchars($campus_filter, ENT_QUOTES) ?>' || 'Pablo Borbon';
            loadCampusDistribution('', campusFilter);
            loadCulturalGroupDistribution('', campusFilter);
            loadStudentArtistOverview();
            loadUpcomingEventsOverview();
            loadCostumeInventoryOverview();
                // Adjust upcoming events list height to match the input panel
                try { if (typeof adjustUpcomingEventsHeight === 'function') adjustUpcomingEventsHeight(); } catch(e) { console.warn(e); }
        });

            // Keep the upcoming events list height in sync with the input panel
            function adjustUpcomingEventsHeight() {
                try {
                    const inputPanel = document.querySelector('.events-left .input-panel');
                    const eventsList = document.getElementById('eventsList');
                    if (!inputPanel || !eventsList) return;

                    // Compute available height inside the input panel (exclude paddings if needed)
                    const panelStyle = window.getComputedStyle(inputPanel);
                    const panelPaddingTop = parseFloat(panelStyle.paddingTop) || 0;
                    const panelPaddingBottom = parseFloat(panelStyle.paddingBottom) || 0;
                    const availableHeight = inputPanel.clientHeight - panelPaddingTop - panelPaddingBottom;

                    // Apply the height to the events list so it scrolls internally when content overflows
                    eventsList.style.maxHeight = availableHeight + 'px';
                    eventsList.style.overflowY = 'auto';
                } catch (err) {
                    console.error('adjustUpcomingEventsHeight error:', err);
                }
            }

            // Recompute on resize and when DOM changes in case panels are toggled
            window.addEventListener('resize', function() {
                try { adjustUpcomingEventsHeight(); } catch(e) { /* ignore */ }
            });

        // Campus Distribution Functions
        function filterByCampus() {
            const selectedCampus = document.getElementById('campusFilterDropdown').value;
            // Update URL with campus filter parameter and reload page
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('campus_filter', selectedCampus);
            urlParams.set('section', 'student-profiles');
            window.location.href = '?' + urlParams.toString();
        }

        function loadCampusDistribution(searchTerm = '', filterCampus = 'Pablo Borbon') {
            console.log('Loading campus distribution with search:', searchTerm, 'campus:', filterCampus);
            let url = 'get_college_distribution.php';
            const params = new URLSearchParams();
            if (searchTerm) params.append('search', searchTerm);
            if (filterCampus) params.append('campus', filterCampus);
            if (params.toString()) url += '?' + params.toString();
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    console.log('Campus distribution response:', data);
                    if (data.success) {
                        displayCampusDistribution(data.collegeDistribution, data.totalStudents, filterCampus);
                    } else {
                        console.error('Failed to load campus distribution:', data.error);
                        showEmptyCampusChart();
                    }
                })
                .catch(error => {
                    console.error('Error loading campus distribution:', error);
                    showEmptyCampusChart();
                });
        }

        function displayCampusDistribution(collegeData, totalStudents, filterCampus = 'Pablo Borbon') {
            console.log('College data received:', collegeData, 'filter:', filterCampus);
            
            // Get selected campus from dropdown if not passed
            if (!filterCampus) {
                const dropdown = document.getElementById('campusFilterDropdown');
                if (dropdown) {
                    filterCampus = dropdown.value || 'Pablo Borbon';
                }
            }
            
            // Define college lists for each campus
            const campusColleges = {
                'Pablo Borbon': [
                    'College of Accountancy, Business, Economics, & International Hospitality Management',
                    'College of Arts and Sciences',
                    'College of Law',
                    'College of Health Sciences / Nursing & Allied Health',
                    'College of Teacher Education',
                    'College of Medicine'
                ],
                'Alangilan': [
                    'College of Engineering',
                    'College of Architecture, Fine Arts and Design',
                    'College of Engineering Technology',
                    'College of Informatics and Computing Sciences',
                    'Lobo Campus',
                    'Balayan Campus',
                    'Mabini Campus'
                ],
                'JPLPC Malvar': [
                    'College of Industrial Technology',
                    'College of Teacher Education',
                    'College of Engineering',
                    'College of Informatics and Computing Sciences',
                    'College of Arts and Sciences',
                    'College of Accountancy, Business, Economics & International Hospitality Management'
                ],
                'Lipa': [
                    'College of Accountancy, Business, Economics & International Hospitality Management',
                    'College of Arts and Sciences',
                    'College of Engineering',
                    'College of Engineering Technology',
                    'College of Informatics and Computing Sciences',
                    'College of Teacher Education'
                ],
                'ARASOF Nasugbu': [
                    'College of Teacher Education',
                    'College of Accountancy, Business, Economics & International Hospitality Management',
                    'College of Informatics & Computing Sciences',
                    'College of Arts and Sciences',
                    'College of Health Sciences / Allied Health',
                    'College of Criminal Justice Education'
                ]
            };
            
            // Get the college order based on selected campus filter
            let collegeOrder = campusColleges[filterCampus] || [];
            
            // Create a map of existing data
            const dataMap = {};
            if (collegeData && collegeData.length > 0) {
                collegeData.forEach(item => {
                    dataMap[item.college] = item.count;
                });
            }
            
            // Build ordered data with all colleges, using 0 for missing ones
            const orderedData = collegeOrder.map(college => ({
                college: college,
                count: dataMap[college] || 0,
                percentage: 0 // Will be calculated below
            }));
            
            // Calculate percentages based on max count
            const maxCount = Math.max(...orderedData.map(c => c.count), 1); // Ensure at least 1 to avoid division by zero
            orderedData.forEach(item => {
                item.percentage = maxCount > 0 ? (item.count / maxCount) * 100 : 0;
            });

            // Red color palette for bars (from light to dark)
            const colors = [
                '#ff6b6b', // Light red
                '#ee5a52', // 
                '#dc2626', // Medium red
                '#c91f1f', // 
                '#b91c1c', // Dark red
                '#a81818', // 
                '#991515', // 
                '#8b1414', // 
                '#7f1d1d', // Very dark red
                '#6b1717'  // Darkest red
            ];
            
            // Draw bar chart with ordered data
            drawBarChart(orderedData, colors);
        }

        function drawBarChart(collegeData, colors) {
            const barContainer = document.getElementById('barChart');
            barContainer.innerHTML = '';
            
            const maxCount = Math.max(...collegeData.map(c => c.count), 1); // Ensure at least 1 to avoid division by zero
            
            // Reset container styles for horizontal bars
            barContainer.style.display = 'block';
            barContainer.style.padding = '1rem';
            
            collegeData.forEach((college, index) => {
                const barWrapper = document.createElement('div');
                barWrapper.style.marginBottom = '1rem';
                barWrapper.style.display = 'flex';
                barWrapper.style.alignItems = 'center';
                barWrapper.style.gap = '1rem';
                
                // Calculate percentage based on the maximum count
                const percentage = maxCount > 0 ? (college.count / maxCount) * 100 : 0;
                
                barWrapper.innerHTML = `
                    <div style="
                        width: 200px;
                        flex-shrink: 0;
                        text-align: right;
                        font-size: 0.75rem;
                        color: #333;
                        font-weight: 500;
                        padding-right: 0.5rem;
                    ">${college.college}</div>
                    <div style="
                        flex: 1;
                        height: 35px;
                        background: #f0f0f0;
                        border-radius: 4px;
                        position: relative;
                        overflow: hidden;
                    ">
                        <div style="
                            height: 100%;
                            width: ${percentage}%;
                            background: ${colors[index % colors.length]};
                            display: flex;
                            align-items: center;
                            justify-content: flex-end;
                            padding-right: 0.75rem;
                            transition: width 0.3s ease;
                            min-width: ${college.count > 0 ? '30px' : '0'};
                        ">
                            <span style="
                                color: white;
                                font-weight: bold;
                                font-size: 0.875rem;
                            ">${college.count}</span>
                        </div>
                    </div>
                `;
                
                barContainer.appendChild(barWrapper);
            });
        }

        function showEmptyCampusChart() {
            const chartContainer = document.getElementById('campusDistributionChart');
            chartContainer.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: center; height: 300px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #ddd;">
                    <div style="text-align: center; color: #888;">
                        <p style="font-size: 1rem; margin-bottom: 0.5rem;">No data available</p>
                        <small style="font-size: 0.875rem; color: #aaa;">No student artists found or no college assigned</small>
                    </div>
                </div>
            `;
        }

        // Cultural Group Distribution Functions
        function loadCulturalGroupDistribution(searchTerm = '', filterCampus = '') {
            console.log('Loading cultural group distribution with search:', searchTerm, 'campus:', filterCampus);
            let url = 'get_cultural_group_distribution.php';
            const params = new URLSearchParams();
            if (searchTerm) params.append('search', searchTerm);
            if (filterCampus) params.append('campus', filterCampus);
            // Add cache-busting parameter
            params.append('t', new Date().getTime());
            if (params.toString()) url += '?' + params.toString();
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    console.log('Cultural group distribution response:', data);
                    if (data.success) {
                        displayCulturalGroupDistribution(data.groupDistribution, data.totalStudents);
                    } else {
                        console.error('Failed to load cultural group distribution:', data.error);
                        showEmptyGroupChart();
                    }
                })
                .catch(error => {
                    console.error('Error loading cultural group distribution:', error);
                    showEmptyGroupChart();
                });
        }

        function displayCulturalGroupDistribution(groupData, totalStudents) {
            const container = document.getElementById('culturalGroupCounts');
            
            if (!groupData || groupData.length === 0) {
                showEmptyGroupChart();
                return;
            }

            let html = '<div class="cultural-group-list">';

            groupData.forEach(group => {
                html += `
                    <div class="group-item">
                        <span class="group-name">${group.group_name}</span>
                        <span class="group-count">${group.count}</span>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        }

        function showEmptyGroupChart() {
            const container = document.getElementById('culturalGroupCounts');
            container.innerHTML = `
                <div class="record-number">0</div>
                <div class="record-label">student artists</div>
                <div class="record-sublabel">No groups assigned</div>
            `;
        }

        // Student Artist Overview Functions
        function loadStudentArtistOverview() {
            console.log('Loading student artist overview...');
            fetch('get_student_artist_overview.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Student artist overview response:', data);
                    if (data.success) {
                        displayStudentArtistOverview(data);
                    } else {
                        console.error('Failed to load student artist overview:', data.error);
                        showEmptyStudentOverview();
                    }
                })
                .catch(error => {
                    console.error('Error loading student artist overview:', error);
                    showEmptyStudentOverview();
                });
        }

        function displayStudentArtistOverview(data) {
            const container = document.querySelector('.content-panel:first-child .panel-content');
            if (!container) return;

            const stats = data.statistics;
            const participation = data.participation;
            const recentActivity = data.recent_activity;
            
            const participationRate = Math.round(participation.participation_rate || 0);
            
            container.innerHTML = `
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #28a745; font-style: normal;">${stats.total_students}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Total Students</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8; font-style: normal;">${stats.assigned_students}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Assigned</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #ffc107; font-style: normal;">${stats.active_students}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Active</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #6f42c1; font-style: normal;">${participationRate}%</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Participation</div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 0.9rem; font-style: normal;">Recent Event Activity</h4>
                        <div style="max-height: 120px; overflow-y: auto;">
                            ${recentActivity.length > 0 ? recentActivity.map(activity => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem; border-bottom: 1px solid #eee; font-size: 0.8rem; font-style: normal;">
                                    <span style="font-weight: 500; font-style: normal;">${activity.event_title}</span>
                                    <span style="color: #666; font-style: normal;">${activity.actual_participants || 0} participant(s)</span>
                                </div>
                            `).join('') : '<div style="color: #888; font-size: 0.8rem; text-align: center; padding: 1rem; font-style: normal;">No recent activity</div>'}
                        </div>
                    </div>
                </div>
            `;
        }

        function showEmptyStudentOverview() {
            const container = document.querySelector('.content-panel:first-child .panel-content');
            if (!container) return;
            
            container.innerHTML = `
                <div style="text-align: center; color: #888; padding: 2rem;">
                    <p>Unable to load student artist data</p>
                </div>
            `;
        }

        // Upcoming Events Overview Functions
        function loadUpcomingEventsOverview() {
            console.log('Loading upcoming events overview...');
            fetch('get_upcoming_events_overview.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Upcoming events overview response:', data);
                    if (data.success) {
                        displayUpcomingEventsOverview(data);
                    } else {
                        console.error('Failed to load upcoming events overview:', data.error);
                        showUpcomingEventsError();
                    }
                })
                .catch(error => {
                    console.error('Error loading upcoming events overview:', error);
                    showUpcomingEventsError();
                });
        }

        function displayUpcomingEventsOverview(data) {
            const container = document.querySelector('.content-panel:nth-child(2) .panel-content');
            if (!container) return;

            const stats = data.statistics;
            const upcomingEvents = data.upcoming_events;
            
            container.innerHTML = `
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #28a745; font-style: normal;">${stats.total_upcoming}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Upcoming</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #dc3545; font-style: normal;">${stats.this_week}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">This Week</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8; font-style: normal;">${stats.trainings}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Trainings</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #6f42c1; font-style: normal;">${stats.performances}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Shows</div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 0.9rem; font-style: normal;">Next Events</h4>
                        <div style="max-height: 120px; overflow-y: auto;">
                            ${upcomingEvents.length > 0 ? upcomingEvents.map(event => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem; border-bottom: 1px solid #eee; font-size: 0.8rem; font-style: normal;">
                                    <div style="font-style: normal;">
                                        <div style="font-weight: 500; font-style: normal;">${event.title}</div>
                                        <div style="color: #666; font-size: 0.7rem; font-style: normal;">${new Date(event.start_date).toLocaleDateString()}</div>
                                    </div>
                                    <span style="color: #666; font-style: normal;">${event.registered_count || 0} registered</span>
                                </div>
                            `).join('') : '<div style="color: #888; font-size: 0.8rem; text-align: center; padding: 1rem; font-style: normal;">No upcoming events</div>'}
                        </div>
                    </div>
                </div>
            `;
        }

        function showUpcomingEventsError() {
            const container = document.querySelector('.content-panel:nth-child(2) .panel-content');
            if (!container) return;
            
            container.innerHTML = `
                <div style="text-align: center; color: #888; padding: 2rem; font-style: normal;">
                    <p>Unable to load upcoming events data</p>
                </div>
            `;
        }

        // Costume Inventory Overview Functions
        function loadCostumeInventoryOverview() {
            console.log('Loading costume inventory overview...');
            fetch('get_costume_inventory_overview.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Costume inventory overview response:', data);
                    if (data.success) {
                        displayCostumeInventoryOverview(data);
                    } else {
                        console.error('Failed to load costume inventory overview:', data.error);
                        showEmptyCostumeInventory();
                    }
                })
                .catch(error => {
                    console.error('Error loading costume inventory overview:', error);
                    showEmptyCostumeInventory();
                });
        }

        function displayCostumeInventoryOverview(data) {
            const container = document.querySelector('.content-panel:nth-child(3) .panel-content');
            if (!container) return;

            const stats = data.statistics;
            const itemsNeedingAttention = data.items_needing_attention;
            
            container.innerHTML = `
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #28a745; font-style: normal;">${stats.total_items}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Total Items</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8; font-style: normal;">${stats.available_items}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Available</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #ffc107; font-style: normal;">${stats.borrowed_items}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Borrowed</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px;">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #6c757d; font-style: normal;">${stats.unavailable_items || 0}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Unavailable</div>
                        </div>
                        <div style="text-align: center; padding: 0.75rem; background: #f8f9fa; border-radius: 6px; grid-column: 1 / -1; justify-self: center; width: 100%; max-width: calc(50% - 10px);">
                            <div style="font-size: 1.5rem; font-weight: bold; color: #dc3545; font-style: normal;">${stats.damaged_items}</div>
                            <div style="font-size: 0.8rem; color: #666; font-style: normal;">Damaged</div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 0.9rem; font-style: normal;">Needs Attention</h4>
                        <div style="max-height: 120px; overflow-y: auto;">
                            ${itemsNeedingAttention.length > 0 ? itemsNeedingAttention.map(item => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem; border-bottom: 1px solid #eee; font-size: 0.8rem; font-style: normal;">
                                    <div style="font-style: normal;">
                                        <div style="font-weight: 500; font-style: normal;">${item.item_name}</div>
                                        <div style="color: #666; font-size: 0.7rem; font-style: normal;">${item.category || 'General'}</div>
                                    </div>
                                    <span style="color: ${item.condition_status === 'damaged' ? '#dc3545' : '#ffc107'}; font-style: normal; font-size: 0.7rem;">
                                        ${item.condition_status === 'damaged' ? 'Damaged' : item.status === 'maintenance' ? 'Maintenance' : 'Poor'}
                                    </span>
                                </div>
                            `).join('') : '<div style="color: #888; font-size: 0.8rem; text-align: center; padding: 1rem; font-style: normal;">All items in good condition</div>'}
                        </div>
                    </div>
                </div>
            `;
        }

        function showEmptyCostumeInventory() {
            const container = document.querySelector('.content-panel:nth-child(3) .panel-content');
            if (!container) return;
            
            container.innerHTML = `
                <div style="text-align: center; color: #888; padding: 2rem; font-style: normal;">
                    <p>Unable to load costume inventory data</p>
                </div>
            `;
        }

        // Events Management Functions
        function openAddEventModal() {
            // For now, just focus on the form - can be expanded to a modal later
            document.getElementById('eventTitle').focus();
        }

        function viewAllEvents() {
            const modal = document.getElementById('allEventsModal');
            modal.style.display = 'flex';
            loadAllEvents();
            preventBackgroundScroll();
        }

        function closeAllEventsModal() {
            const modal = document.getElementById('allEventsModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        // Costume Inventory Functions
        function openBorrowRequests() {
            // Show the borrow requests modal
            const modal = document.getElementById('borrowRequestsModal');
            modal.style.display = 'flex';
            preventBackgroundScroll();
            loadBorrowRequests();
        }

        function closeBorrowRequestsModal() {
            const modal = document.getElementById('borrowRequestsModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        function loadBorrowRequests(page = 1) {
            const loadingDiv = document.getElementById('borrowRequestsLoading');
            const contentDiv = document.getElementById('borrowRequestsContent');
            const tableBody = document.getElementById('borrowRequestsTableBody');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Get filter values
            const status = document.getElementById('statusRequestFilter').value;
            const search = document.getElementById('requestSearchInput') ? document.getElementById('requestSearchInput').value : '';
            
            // Build query parameters
            const params = new URLSearchParams({
                page: page,
                limit: 10
            });
            
            if (status) params.append('status', status);
            if (search) params.append('search', search);
            
            fetch(`get_borrowing_requests.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayBorrowRequests(data.data);
                        displayRequestsPagination(data.pagination);
                    } else {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem; color: #dc3545;">
                                    Error loading requests: ${data.error}
                                </td>
                            </tr>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading borrow requests:', error);
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem; color: #dc3545;">
                                Error loading borrow requests. Please try again.
                            </td>
                        </tr>
                    `;
                });
        }
        
        function displayBorrowRequests(requests) {
            const tableBody = document.getElementById('borrowRequestsTableBody');
            
            if (!requests || requests.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 2rem; color: #666;">
                            No borrow requests found
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            requests.forEach(request => {
                // Use item_name from the database, or fallback to equipment_categories for older requests
                let equipmentList = request.item_name || '';
                if (!equipmentList && request.equipment_categories) {
                    const categories = [];
                    for (const [category, items] of Object.entries(request.equipment_categories)) {
                        if (items) {
                            // Handle both string and array formats
                            if (Array.isArray(items) && items.length > 0) {
                                categories.push(`${category.charAt(0).toUpperCase() + category.slice(1)}: ${items.join(', ')}`);
                            } else if (typeof items === 'string' && items.trim().length > 0) {
                                categories.push(`${category.charAt(0).toUpperCase() + category.slice(1)}: ${items.trim()}`);
                            }
                        }
                    }
                    equipmentList = categories.join('<br>');
                }
                
                html += `
                    <tr>
                        <td style="padding: 0.75rem;">
                            <div style="font-weight: 600;">${request.student_name || 'Unknown Student'}</div>
                            <div style="font-size: 0.8rem; color: #666;">${request.student_email || ''}</div>
                            <div style="font-size: 0.8rem; color: #666;">${request.student_campus || ''}</div>
                            <div style="font-size: 0.8rem; color: #666;">${request.created_at_formatted || ''}</div>
                        </td>
                        <td style="padding: 0.75rem;">
                            <div style="font-size: 0.85rem; max-width: 200px;">${equipmentList || 'Equipment request details not specified'}</div>
                        </td>
                        <td style="padding: 0.75rem;">
                            <div style="font-size: 0.85rem;">${request.dates_of_use || 'Not specified'}</div>
                            <div style="font-size: 0.8rem; color: #666;">Return: ${request.estimated_return_date_formatted || 'Not specified'}</div>
                        </td>
                        <td style="padding: 0.75rem;">
                            ${getStatusBadge(request.status)}
                        </td>
                        <td style="padding: 0.75rem;">
                            ${getRequestActions(request.id, request.status)}
                        </td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = html;
        }
        
        function displayRequestsPagination(pagination) {
            const paginationDiv = document.getElementById('borrowRequestsPagination');
            if (!paginationDiv) return;
            
            if (pagination.total_pages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = '<div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 1rem;">';
            
            // Previous button
            if (pagination.current_page > 1) {
                html += `<button class="pagination-btn" onclick="loadBorrowRequests(${pagination.current_page - 1})">Previous</button>`;
            }
            
            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                html += `<button class="pagination-number ${activeClass}" onclick="loadBorrowRequests(${i})">${i}</button>`;
            }
            
            // Next button
            if (pagination.current_page < pagination.total_pages) {
                html += `<button class="pagination-btn" onclick="loadBorrowRequests(${pagination.current_page + 1})">Next</button>`;
            }
            
            html += '</div>';
            html += `<div style="text-align: center; margin-top: 0.5rem; font-size: 0.9rem; color: #666;">
                Showing ${((pagination.current_page - 1) * pagination.limit) + 1} to ${Math.min(pagination.current_page * pagination.limit, pagination.total_requests)} of ${pagination.total_requests} requests
            </div>`;
            
            paginationDiv.innerHTML = html;
        }
        
        function getStatusBadge(status) {
            const badges = {
                'pending': '<span class="status-badge pending">Pending</span>',
                'approved': '<span class="status-badge approved">Approved</span>',
                'rejected': '<span class="status-badge rejected">Rejected</span>'
            };
            return badges[status] || status;
        }
        
        function getRequestActions(requestId, status) {
            if (status === 'pending') {
                return `
                    <button class="action-btn small approve" onclick="openApprovalModal(${requestId})">Approve</button>
                    <button class="action-btn small reject" onclick="rejectRequest(${requestId})">Reject</button>
                `;
            } else {
                return `<button class="action-btn small view" onclick="viewRequest(${requestId})">View</button>`;
            }
        }
        
        function openApprovalModal(requestId) {
            openItemSelectionModal(requestId);
        }
        
        function approveRequest(requestId) {
            if (confirm('Are you sure you want to approve this borrow request?')) {
                updateRequestStatus(requestId, 'approved');
            }
        }
        
        function rejectRequest(requestId) {
            if (confirm('Are you sure you want to reject this borrow request?')) {
                updateRequestStatus(requestId, 'rejected');
            }
        }
        
        function updateRequestStatus(requestId, status) {
            fetch('update_borrowing_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    request_id: requestId,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    loadBorrowRequests(); // Reload the borrow requests list
                    
                    // If the request was approved, also refresh inventory to show updated status
                    if (status === 'approved') {
                        loadInventoryItems(); // Refresh inventory to show items as "borrowed"
                    }
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error updating request:', error);
                alert('Error updating request. Please try again.');
            });
        }
        
        function viewRequest(requestId) {
            // Here you would show detailed view of the request
            alert('Viewing request details for ID: ' + requestId);
        }

        // Returns Functions
        function openReturns() {
            // Show the returns modal
            const modal = document.getElementById('returnsModal');
            modal.style.display = 'flex';
            preventBackgroundScroll();
            loadReturnRequests();
        }

        function closeReturnsModal() {
            const modal = document.getElementById('returnsModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        function loadReturnRequests(page = 1) {
            const loadingDiv = document.getElementById('returnsLoading');
            const contentDiv = document.getElementById('returnsContent');
            const tableBody = document.getElementById('returnRequestsTableBody');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Get filter values
            const status = document.getElementById('statusReturnFilter').value;
            const search = document.getElementById('searchReturnFilter').value;
            
            // API call to fetch return requests
            fetch('get_return_requests.php?' + new URLSearchParams({
                page: page,
                status: status,
                search: search
            }))
            .then(response => response.json())
            .then(data => {
                loadingDiv.style.display = 'none';
                contentDiv.style.display = 'block';

                if (data.success) {
                    displayReturnRequests(data.requests);
                    // Render pagination controls if provided by the API
                    if (data.pagination) {
                        displayReturnRequestsPagination(data.pagination);
                    }
                } else {
                    tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #666; padding: 2rem;">Error loading return requests: ${data.message}</td></tr>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #666; padding: 2rem;">Error loading return requests</td></tr>`;
                loadingDiv.style.display = 'none';
                contentDiv.style.display = 'block';
            });
        }

        function displayReturnRequests(requests) {
            const tableBody = document.getElementById('returnRequestsTableBody');
            
            if (!requests || requests.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #666; padding: 2rem;">No return requests found</td></tr>`;
                return;
            }

            let html = '';
            requests.forEach(request => {
                const statusBadge = getReturnRequestStatusBadge(request.status);
                const actions = getReturnRequestActions(request.id, request.status);
                
                html += `
                    <tr>
                        <td>${request.student_name}</td>
                        <td>${request.item_name}</td>
                        <td>${formatDate(request.requested_at)}</td>
                        <td>${request.condition_notes || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = html;
        }

        function displayReturnRequestsPagination(pagination) {
            const paginationDiv = document.getElementById('returnsPagination');
            if (!paginationDiv) return;

            if (pagination.total_pages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }

            let html = '<div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 1rem;">';

            // Previous button
            if (pagination.current_page > 1) {
                html += `<button class="pagination-btn" onclick="loadReturnRequests(${pagination.current_page - 1})">Previous</button>`;
            }

            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);

            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                html += `<button class="pagination-number ${activeClass}" onclick="loadReturnRequests(${i})">${i}</button>`;
            }

            // Next button
            if (pagination.current_page < pagination.total_pages) {
                html += `<button class="pagination-btn" onclick="loadReturnRequests(${pagination.current_page + 1})">Next</button>`;
            }

            html += '</div>';

            const perPage = pagination.per_page || 10;
            const total = pagination.total_count || 0;
            const startItem = ((pagination.current_page - 1) * perPage) + 1;
            const endItem = Math.min(pagination.current_page * perPage, total);

            html += `<div style="text-align: center; margin-top: 0.5rem; font-size: 0.9rem; color: #666;">Showing ${startItem} to ${endItem} of ${total} requests</div>`;

            paginationDiv.innerHTML = html;
        }

        let returnSearchTimeout;
        function debounceReturnSearch() {
            clearTimeout(returnSearchTimeout);
            returnSearchTimeout = setTimeout(() => {
                loadReturnRequests();
            }, 500);
        }
        
        function getReturnRequestStatusBadge(status) {
            const badges = {
                'pending': '<span class="status-badge pending">Pending</span>',
                'completed': '<span class="status-badge approved">Completed</span>'
            };
            return badges[status] || status;
        }
        
        function getReturnRequestActions(requestId, status) {
            if (status === 'pending') {
                return `
                    <button class="action-btn small approve" onclick="confirmReturn(${requestId})">Confirm Return</button>
                    <button class="action-btn small view" onclick="viewReturnRequest(${requestId})">View Details</button>
                `;
            } else {
                return `
                    <button class="action-btn small view" onclick="viewReturnRequest(${requestId})">View Details</button>
                `;
            }
        }
        
        function confirmReturn(requestId) {
            if (confirm('Are you sure you want to confirm this return? This will mark the items as returned and available.')) {
                fetch('process_return_confirmation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        request_id: requestId,
                        action: 'confirm_return'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Return confirmed successfully!');
                        loadReturnRequests(); // Refresh the return requests list
                        loadInventoryItems(); // Refresh the inventory to show updated status
                    } else {
                        alert('Error confirming return: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error confirming return: ' + error.message);
                });
            }
        }
        
        function viewReturnRequest(requestId) {
            // TODO: Implement view return request details
            alert('View return request details for ID: ' + requestId);
        }

        function getReturnRequestActions(requestId, status) {
            if (status === 'pending') {
                return `
                    <button class="action-btn small confirm" onclick="confirmReturn(${requestId})" style="background: #10b981; color: white; margin-right: 0.5rem;">
                        Confirm
                    </button>
                    <button class="action-btn small view" onclick="viewReturnRequest(${requestId})">
                        View
                    </button>
                `;
            } else {
                return `<button class="action-btn small view" onclick="viewReturnRequest(${requestId})">View</button>`;
            }
        }

        function getReturnRequestStatusBadge(status) {
            const statusConfig = {
                'pending': { text: 'Pending', color: '#f59e0b', bg: '#fef3c7' },
                'confirmed': { text: 'Confirmed', color: '#10b981', bg: '#d1fae5' },
                'cancelled': { text: 'Cancelled', color: '#ef4444', bg: '#fee2e2' }
            };
            
            const config = statusConfig[status] || { text: status, color: '#6b7280', bg: '#f3f4f6' };
            
            return `
                <span style="
                    display: inline-block;
                    padding: 0.25rem 0.5rem;
                    border-radius: 0.375rem;
                    font-size: 0.75rem;
                    font-weight: 500;
                    color: ${config.color};
                    background-color: ${config.bg};
                ">
                    ${config.text}
                </span>
            `;
        }

        // Add Item Functions
        function openAddItemModal() {
            const modal = document.getElementById('addItemModal');
            modal.style.display = 'flex';
            preventBackgroundScroll();
            // Reset form
            document.getElementById('addItemForm').reset();
        }

        function closeAddItemModal() {
            const modal = document.getElementById('addItemModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        // Handle Add Item Form Submission
        document.addEventListener('DOMContentLoaded', function() {
            const addItemForm = document.getElementById('addItemForm');
            if (addItemForm) {
                addItemForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const itemData = {
                        name: formData.get('name'),
                        category: formData.get('category'),
                        quantity: formData.get('quantity'),
                        condition: formData.get('condition'),
                        description: formData.get('description'),
                        status: 'available' // Automatically set to available
                    };
                    
                    // Send data to backend
                    fetch('save_inventory_item.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(itemData)
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        console.log('Response headers:', response.headers.get('content-type'));
                        
                        // Check if response is actually JSON
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            return response.text().then(text => {
                                throw new Error('Server returned non-JSON response: ' + text);
                            });
                        }
                        
                        return response.json();
                    })
                    .then(data => {
                        console.log('Parsed data:', data);
                        if (data.success) {
                            alert('Item added successfully! Status automatically set to "Available".');
                            closeAddItemModal();
                            loadInventoryItems(); // Reload the tables
                        } else {
                            alert('Error adding item: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Full error:', error);
                        alert('Error adding item: ' + error.message);
                    });
                });
            }
        });

        function loadInventoryItems() {
            // Load costumes and equipment from the database
            console.log('Loading inventory items...');
            
            fetch('get_inventory_items.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayCostumes(data.costumes);
                        displayEquipment(data.equipment);
                    } else {
                        console.error('Error loading inventory:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading inventory:', error);
                });
        }

        function displayCostumes(costumes) {
            const tableBody = document.getElementById('costumesTableBody');
            if (!costumes || costumes.length === 0) {
                tableBody.innerHTML = '<div class="empty-state" style="padding: 2rem; text-align: center; color: #666;"><p>No costumes found.</p>' + (canManage ? '<small>Click "Add Costume" to get started.</small>' : '') + '</div>';
                return;
            }
            
            let html = '';
            costumes.forEach(costume => {
                html += '<div class="table-row compact-row" style="display: grid; grid-template-columns: 2fr 60px 90px 90px 1.5fr; padding: 0.65rem 0.5rem; border-bottom: 1px solid #e0e0e0; align-items: center; font-size: 0.85rem;">';
                html += '<div style="padding: 0 0.3rem; font-weight: 500; word-wrap: break-word; overflow-wrap: break-word; line-height: 1.3;">' + (costume.item_name || costume.name || 'Unnamed Item') + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; font-weight: 600; color: #333;">' + (costume.quantity || 0) + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; display: flex; justify-content: center; align-items: center;">' + getConditionBadge(costume.condition_status) + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; display: flex; justify-content: center; align-items: center;">' + getInventoryStatusBadge(costume.status) + '</div>';
                html += '<div style="padding: 0 0.3rem; font-size: 0.8rem; line-height: 1.3;">' + getBorrowerInfo(costume) + '</div>';
                html += '</div>';
            });
            tableBody.innerHTML = html;
        }

        function displayEquipment(equipment) {
            const tableBody = document.getElementById('equipmentTableBody');
            if (!equipment || equipment.length === 0) {
                tableBody.innerHTML = '<div class="empty-state" style="padding: 2rem; text-align: center; color: #666;"><p>No equipment found.</p>' + (canManage ? '<small>Click "Add Costume" to get started.</small>' : '') + '</div>';
                return;
            }
            
            let html = '';
            equipment.forEach(item => {
                html += '<div class="table-row compact-row" style="display: grid; grid-template-columns: 2fr 60px 90px 90px 1.5fr; padding: 0.65rem 0.5rem; border-bottom: 1px solid #e0e0e0; align-items: center; font-size: 0.85rem;">';
                html += '<div style="padding: 0 0.3rem; font-weight: 500; word-wrap: break-word; overflow-wrap: break-word; line-height: 1.3;">' + (item.item_name || item.name || 'Unnamed Item') + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; font-weight: 600; color: #333;">' + (item.quantity || 0) + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; display: flex; justify-content: center; align-items: center;">' + getConditionBadge(item.condition_status) + '</div>';
                html += '<div style="padding: 0 0.3rem; text-align: center; display: flex; justify-content: center; align-items: center;">' + getInventoryStatusBadge(item.status) + '</div>';
                html += '<div style="padding: 0 0.3rem; font-size: 0.8rem; line-height: 1.3;">' + getBorrowerInfo(item) + '</div>';
                html += '</div>';
            });
            tableBody.innerHTML = html;
        }

        function getBorrowerInfo(item) {
            if (item.status === 'borrowed') {
                // Check if there are multiple borrowers
                if (item.borrowers && item.borrowers.length > 0) {
                    let html = '<div style="line-height: 1.3;">';
                    
                    // Show all borrower names
                    item.borrowers.forEach((borrower, index) => {
                        const borrowDate = new Date(borrower.borrow_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        html += `<div style="margin-bottom: ${index < item.borrowers.length - 1 ? '0.4rem' : '0'}; padding-bottom: ${index < item.borrowers.length - 1 ? '0.4rem' : '0'}; border-bottom: ${index < item.borrowers.length - 1 ? '1px solid #eee' : 'none'};">`;
                        html += `<div style="font-weight: 600; color: #333; margin-bottom: 2px; font-size: 0.8rem; word-wrap: break-word; overflow-wrap: break-word;">${borrower.student_name}</div>`;
                        html += `<div style="color: #666; font-size: 0.7rem;">Since: ${borrowDate}</div>`;
                        html += `</div>`;
                    });
                    
                    html += '</div>';
                    return html;
                } else if (item.borrower_name) {
                    // Fallback to single borrower for backward compatibility
                    const borrowDate = new Date(item.borrow_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    return `<div style="line-height: 1.3;">
                        <div style="font-weight: 600; color: #333; margin-bottom: 2px; font-size: 0.8rem; word-wrap: break-word; overflow-wrap: break-word;">${item.borrower_name}</div>
                        <div style="color: #666; font-size: 0.7rem;">Since: ${borrowDate}</div>
                    </div>`;
                } else {
                    return '<span style="color: #666; font-style: italic; font-size: 0.8rem;">Borrowed</span>';
                }
            }
            return '<span style="color: #aaa; text-align: center; display: block; font-size: 0.8rem;">-</span>';
        }

        function getConditionBadge(condition) {
            const badges = {
                'excellent': '<span style="background: #28a745; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Excellent</span>',
                'good': '<span style="background: #28a745; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Good</span>',
                'fair': '<span style="background: #ffc107; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Fair</span>',
                'poor': '<span style="background: #fd7e14; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Poor</span>',
                'worn-out': '<span style="background: #dc3545; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Worn-out</span>',
                'damaged': '<span style="background: #dc3545; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Damaged</span>',
                'bad': '<span style="background: #dc3545; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; white-space: nowrap; display: inline-block;">Bad</span>'
            };
            return badges[condition] || `<span style="color: #666; font-size: 0.7rem;">${condition || 'Unknown'}</span>`;
        }

        function getInventoryStatusBadge(status) {
            const badges = {
                'available': '<span style="background: #28a745; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block;">Available</span>',
                'borrowed': '<span style="background: #6c757d; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block;">Borrowed</span>',
                'maintenance': '<span style="background: #fd7e14; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block;">Maintenance</span>',
                'reserved': '<span style="background: #17a2b8; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block;">Reserved</span>',
                'retired': '<span style="background: #6c757d; color: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; display: inline-block;">Retired</span>'
            };
            return badges[status] || `<span style="color: #666; font-size: 0.7rem;">${status || 'Unknown'}</span>`;
        }

        // Load inventory items when costume inventory section is activated
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const sectionId = this.dataset.section;
                    if (sectionId === 'costume-inventory') {
                        // Load inventory items when costume inventory section is activated
                        setTimeout(() => {
                            loadInventoryItems();
                        }, 100);
                    }
                });
            });

            // Load inventory if costume-inventory is the initial active section
            const urlParams = new URLSearchParams(window.location.search);
            const activeSection = urlParams.get('section');
            if (activeSection === 'costume-inventory') {
                setTimeout(() => {
                    loadInventoryItems();
                }, 100);
            }
        });

        function loadAllEvents(page = 1) {
            const loadingDiv = document.getElementById('allEventsLoading');
            const contentDiv = document.getElementById('allEventsContent');
            
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Get filter values
            const status = document.getElementById('statusFilter').value;
            const category = document.getElementById('categoryFilter').value;
            const campusFilter = document.getElementById('campusFilter');
            const campus = campusFilter ? campusFilter.value : '';
            const month = document.getElementById('monthFilter').value;
            
            // Build query parameters
            const params = new URLSearchParams({
                page: page,
                limit: 10,
                status: status
            });
            
            if (category) params.append('category', category);
            if (campus) params.append('campus', campus);
            if (month) params.append('month', month);
            
            fetch(`get_all_events.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayAllEvents(data.events);
                        displayEventsPagination(data.pagination);
                    } else {
                        contentDiv.innerHTML = '<p class="error">Error loading events: ' + data.message + '</p>';
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    contentDiv.style.display = 'block';
                    contentDiv.innerHTML = '<p class="error">Error loading events: ' + error.message + '</p>';
                });
        }

        function displayAllEvents(events) {
            const contentDiv = document.getElementById('allEventsContent');
            
            if (events.length === 0) {
                contentDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No events found with the selected filters.</p>';
                return;
            }

            let html = '<div style="display: grid; gap: 1rem;">';
            events.forEach(event => {
                const culturalGroups = event.cultural_groups.length > 0 
                    ? event.cultural_groups.join(', ') 
                    : 'All groups';

                const statusColor = event.event_status === 'upcoming' ? '#28a745' : 
                                  event.event_status === 'ongoing' ? '#ffc107' : '#6c757d';

                html += '<div style="border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem; background: white;">';
                html += '<div style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: start;">';
                html += '<div>';
                html += '<h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 1.2rem;">' + event.title + '</h4>';
                html += '<p style="margin: 0 0 0.75rem 0; color: #666; line-height: 1.4;">' + event.description + '</p>';
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem; font-size: 0.9rem;">';
                html += '<div><strong>Date:</strong> ' + event.start_date_formatted + ' - ' + event.end_date_formatted + '</div>';
                html += '<div><strong>Location:</strong> ' + event.location + '</div>';
                html += '<div><strong>Category:</strong> ' + (event.category || 'N/A') + '</div>';
                if (event.campus) {
                    html += '<div><strong>Campus:</strong> ' + event.campus + '</div>';
                }
                html += '<div><strong>Cultural Groups:</strong> ' + culturalGroups + '</div>';
                html += '<div><strong>Created:</strong> ' + event.created_at_formatted + '</div>';
                html += '</div>';
                html += '</div>';
                html += '<div style="text-align: right;">';
                html += '<div style="display: inline-block; background: ' + statusColor + '; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-transform: capitalize; margin-bottom: 0.5rem;">';
                html += event.event_status;
                html += '</div>';
                if (event.days_difference >= 0) {
                    html += '<div style="font-size: 0.8rem; color: #666;">In ' + event.days_difference + ' day(s)</div>';
                } else {
                    html += '<div style="font-size: 0.8rem; color: #666;">' + Math.abs(event.days_difference) + ' day(s) ago</div>';
                }
                if (canManageEvents) {
                    html += '<div style="margin-top: 1rem; display: flex; gap: 0.5rem; justify-content: flex-end;">';
                    html += '<button onclick="editEvent(' + event.id + ')" style="background: #6c757d; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">Edit</button>';
                    html += '<button onclick="archiveEvent(' + event.id + ', \'' + event.title.replace(/'/g, "\\'") + '\')" style="background: #ff9800; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">Archive</button>';
                    html += '</div>';
                }
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';

            contentDiv.innerHTML = html;
        }

        function displayEventsPagination(pagination) {
            const paginationDiv = document.getElementById('eventsPagination');
            
            if (pagination.total_pages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }

            let html = '<div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem;">';
            
            // Previous button
            if (pagination.current_page > 1) {
                html += '<button onclick="loadAllEvents(' + (pagination.current_page - 1) + ')" style="padding: 0.5rem 1rem; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">Previous</button>';
            }
            
            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const isActive = i === pagination.current_page;
                const bgColor = isActive ? '#dc2626' : 'white';
                const textColor = isActive ? 'white' : '#333';
                const borderColor = isActive ? '#dc2626' : '#ddd';
                html += '<button onclick="loadAllEvents(' + i + ')" style="padding: 0.5rem 0.75rem; border: 1px solid ' + borderColor + '; background: ' + bgColor + '; color: ' + textColor + '; border-radius: 4px; cursor: pointer;">' + i + '</button>';
            }
            
            // Next button
            if (pagination.current_page < pagination.total_pages) {
                html += '<button onclick="loadAllEvents(' + (pagination.current_page + 1) + ')" style="padding: 0.5rem 1rem; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">Next</button>';
            }
            
            html += '</div>';
            html += '<div style="text-align: center; margin-top: 0.5rem; font-size: 0.9rem; color: #666;">Showing ' + pagination.total_events + ' total events</div>';
            
            paginationDiv.innerHTML = html;
        }

        // Event Management Functions
        let isEditMode = false;
        let currentEditingEventId = null;
        
        // Initialize campus field based on user permissions
        function initializeCampusField() {
            const municipalityField = document.getElementById('municipality');
            if (!municipalityField || !userCampus) return;
            
            // Set default campus
            municipalityField.value = userCampus;
            
            // If user cannot view all campuses, disable the field
            if (!canViewAll) {
                municipalityField.disabled = true;
                municipalityField.style.backgroundColor = '#f3f4f6';
                municipalityField.style.cursor = 'not-allowed';
                municipalityField.title = 'You can only create events for your campus';
            }
        }

        function editEvent(eventId) {
            isEditMode = true;
            currentEditingEventId = eventId;
            
            // Load event data
            fetch(`get_event.php?id=${eventId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        populateEventForm(data.event);
                        // Scroll to form
                        document.getElementById('eventTitle').scrollIntoView({ behavior: 'smooth' });
                        // Update form title and button
                        document.querySelector('.panel-title-event').textContent = 'Edit Event';
                        document.querySelector('.save-event-btn').textContent = 'Update Event';
                        document.querySelector('.cancel-event-btn').style.display = 'inline-block';
                        // Close modal if open
                        closeAllEventsModal();
                    } else {
                        alert('Error loading event: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading event details');
                });
        }

        function populateEventForm(event) {
            // Populate form fields
            document.getElementById('eventTitle').value = event.title;
            document.getElementById('eventDescription').value = event.description;
            document.getElementById('startDate').value = event.start_date_formatted;
            document.getElementById('endDate').value = event.end_date_formatted;
            document.getElementById('eventLocation').value = event.location;
            
            // Use campus field (not venue)
            const municipalityField = document.getElementById('municipality');
            const eventCampus = event.campus || event.venue || userCampus;
            
            // Only allow editing campus if user can view all
            if (canViewAll) {
                municipalityField.value = eventCampus;
                municipalityField.disabled = false;
            } else {
                // Lock to user's campus
                municipalityField.value = userCampus;
                municipalityField.disabled = true;
            }
            
            document.getElementById('eventCategory').value = event.category;
            
            // Handle cultural groups
            const checkboxes = document.querySelectorAll('input[name="cultural_groups[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = event.cultural_groups.includes(checkbox.value);
            });
            updateCulturalGroupsDisplay();
        }

        function cancelEdit() {
            isEditMode = false;
            currentEditingEventId = null;
            // Reset form
            document.getElementById('eventForm').reset();
            updateCulturalGroupsDisplay();
            
            // Reset campus to user's campus
            const municipalityField = document.getElementById('municipality');
            if (municipalityField && userCampus) {
                municipalityField.value = userCampus;
            }
            
            // Update form title and button
            document.querySelector('.panel-title-event').textContent = 'Input New Event';
            document.querySelector('.save-event-btn').textContent = 'Save Event';
            document.querySelector('.cancel-event-btn').style.display = 'none';
        }

        function archiveEvent(eventId, eventTitle) {
            if (!confirm('Are you sure you want to archive the event "' + eventTitle + '"? You can restore it from the Archives module.')) {
                return;
            }
            
            fetch('archive_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ event_id: eventId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // Refresh events lists
                    loadUpcomingEvents();
                    if (document.getElementById('allEventsModal').style.display === 'flex') {
                        loadAllEvents();
                    }
                } else {
                    alert('Error archiving event: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while archiving the event');
            });
        }

        // Event Form Submission
        document.addEventListener('DOMContentLoaded', function() {
            const eventForm = document.getElementById('eventForm');
            if (eventForm) {
                eventForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    saveEvent();
                });
            }
            
            // Set default campus and manage field state
            const municipalityField = document.getElementById('municipality');
            if (municipalityField && userCampus) {
                // If user cannot view all campuses, lock them to their campus
                if (!canViewAll) {
                    municipalityField.value = userCampus;
                    municipalityField.disabled = true;
                    municipalityField.style.backgroundColor = '#f3f4f6';
                    municipalityField.style.cursor = 'not-allowed';
                } else {
                    // Pablo Borbon users can select any campus, default to their own
                    municipalityField.value = userCampus;
                }
            }
        });

        // Cultural Groups Multi-select Functions
        function toggleCulturalGroupsDropdown() {
            const dropdown = document.getElementById('culturalGroupsDropdown');
            const arrow = document.querySelector('.dropdown-arrow');
            
            if (dropdown.style.display === 'none') {
                dropdown.style.display = 'block';
                arrow.classList.add('open');
            } else {
                dropdown.style.display = 'none';
                arrow.classList.remove('open');
            }
        }

        function updateCulturalGroupsDisplay() {
            const checkboxes = document.querySelectorAll('input[name="cultural_groups[]"]:checked');
            const display = document.getElementById('culturalGroupsDisplay');
            const placeholder = display.querySelector('.placeholder');
            const arrow = display.querySelector('.dropdown-arrow');
            
            // Remove existing tags
            const existingTags = display.querySelector('.selected-groups');
            if (existingTags) {
                existingTags.remove();
            }
            
            if (checkboxes.length === 0) {
                placeholder.style.display = 'inline';
                placeholder.textContent = 'Select cultural groups...';
            } else {
                placeholder.style.display = 'none';
                
                const selectedGroupsContainer = document.createElement('div');
                selectedGroupsContainer.className = 'selected-groups';
                
                checkboxes.forEach(checkbox => {
                    const tag = document.createElement('span');
                    tag.className = 'selected-group-tag';
                    tag.innerHTML = `
                        ${checkbox.value}
                        <span class="remove-tag" onclick="removeCulturalGroup('${checkbox.value}')">&times;</span>
                    `;
                    selectedGroupsContainer.appendChild(tag);
                });
                
                display.insertBefore(selectedGroupsContainer, arrow);
            }
        }

        function removeCulturalGroup(groupName) {
            const checkbox = document.querySelector(`input[name="cultural_groups[]"][value="${groupName}"]`);
            if (checkbox) {
                checkbox.checked = false;
                updateCulturalGroupsDisplay();
            }
        }

        // Add event listeners for cultural groups checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('input[name="cultural_groups[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateCulturalGroupsDisplay);
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const container = document.querySelector('.multi-select-container');
                if (container && !container.contains(event.target)) {
                    const dropdown = document.getElementById('culturalGroupsDropdown');
                    const arrow = document.querySelector('.dropdown-arrow');
                    dropdown.style.display = 'none';
                    arrow.classList.remove('open');
                }
            });
        });

        function saveEvent() {
            const form = document.getElementById('eventForm');
            const formData = new FormData(form);
            
            // Add some validation
            const title = formData.get('title');
            const description = formData.get('description');
            const startDate = formData.get('start_date');
            const endDate = formData.get('end_date');
            const location = formData.get('location');
            const category = formData.get('category');

            if (!title || !description || !startDate || !endDate || !location || !category) {
                alert('Please fill in all required fields');
                return;
            }

            // Check if dates are not in the past
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const startDateObj = new Date(startDate);
            const endDateObj = new Date(endDate);
            
            if (startDateObj < today) {
                alert('Start date cannot be in the past');
                return;
            }
            
            if (endDateObj < today) {
                alert('End date cannot be in the past');
                return;
            }

            if (startDateObj > endDateObj) {
                alert('Start date cannot be after end date');
                return;
            }

            // Show loading state
            const saveBtn = document.querySelector('.save-event-btn');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = isEditMode ? 'Updating...' : 'Saving...';
            saveBtn.disabled = true;

            // Add event ID for edit mode
            if (isEditMode && currentEditingEventId) {
                formData.append('event_id', currentEditingEventId);
            }

            // Determine endpoint
            const endpoint = isEditMode ? 'update_event.php' : 'save_event.php';

            // Send data to server
            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(isEditMode ? 'Event updated successfully!' : 'Event saved successfully!');
                    form.reset();
                    updateCulturalGroupsDisplay(); // Reset multi-select display
                    
                    // Reset edit mode
                    if (isEditMode) {
                        cancelEdit();
                    }
                    
                    loadUpcomingEvents(); // Refresh events list
                } else {
                    alert('Error saving event: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the event');
            })
            .finally(() => {
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
            });
        }

        function loadUpcomingEvents() {
            const eventsList = document.getElementById('eventsList');
            
            // Show loading state
            eventsList.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <p>Loading upcoming events...</p>
                </div>
            `;
            
            fetch('get_upcoming_events.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUpcomingEvents(data.events);
                    } else {
                        eventsList.innerHTML = `
                            <div class="empty-events">
                                <p>Error loading events</p>
                                <small>${data.message}</small>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading events:', error);
                    eventsList.innerHTML = `
                        <div class="empty-events">
                            <p>Error loading upcoming events</p>
                            <small>Please try again later</small>
                        </div>
                    `;
                });
        }

        function displayUpcomingEvents(events) {
            const eventsList = document.getElementById('eventsList');
            
            if (events.length === 0) {
                eventsList.innerHTML = `
                    <div class="empty-events">
                        <p>No upcoming events scheduled</p>
                        ${canManageEvents ? '<small>Add a new event to get started</small>' : ''}
                    </div>
                `;
                return;
            }

            let html = '';
            events.forEach(event => {
                const culturalGroups = event.cultural_groups.length > 0 
                    ? event.cultural_groups.slice(0, 2).join(', ') + 
                      (event.cultural_groups.length > 2 ? ` +${event.cultural_groups.length - 2} more` : '')
                    : 'All groups';

                html += `
                    <div class="event-item">
                        <div class="event-title">${event.title}</div>
                        <div class="event-date">${event.start_date_formatted}</div>
                        <div class="event-location">${event.location}</div>
                        <div class="event-category">${event.category}</div>
                        ${event.campus ? `<div style="font-size: 0.8rem; color: #888; margin-top: 0.25rem;">${event.campus}</div>` : ''}
                        <div style="font-size: 0.8rem; color: #666; margin-top: 0.25rem;">${culturalGroups}</div>
                        ${event.days_until === 0 ? '<div style="font-size: 0.8rem; color: #dc2626; font-weight: 600; margin-top: 0.25rem;">Today!</div>' : 
                          event.days_until === 1 ? '<div style="font-size: 0.8rem; color: #dc2626; font-weight: 600; margin-top: 0.25rem;">Tomorrow</div>' :
                          `<div style="font-size: 0.8rem; color: #888; margin-top: 0.25rem;">In ${event.days_until} day(s)</div>`}
                        ${canManageEvents ? `
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem;">
                            <button onclick="editEvent(${event.id})" style="background: #6c757d; color: white; border: none; padding: 0.3rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.7rem;">
                                Edit
                            </button>
                            <button onclick="archiveEvent(${event.id}, '${event.title.replace(/'/g, "\\'")}');" style="background: #ff9800; color: white; border: none; padding: 0.3rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.7rem;">
                                Archive
                            </button>
                        </div>` : ''}
                    </div>
                `;
            });

            eventsList.innerHTML = html;
        }

        // Item Selection Modal Functions
        let currentRequestId = null;
        let availableItems = { costumes: [], equipment: [] };
        let selectedItems = [];

        function openItemSelectionModal(requestId) {
            currentRequestId = requestId;
            const modal = document.getElementById('itemSelectionModal');
            modal.style.display = 'flex';
            loadAvailableItems();
        }

        function closeItemSelectionModal() {
            const modal = document.getElementById('itemSelectionModal');
            modal.style.display = 'none';
            currentRequestId = null;
            selectedItems = [];
            clearItemSelection();
        }

        function loadAvailableItems() {
            const search = document.getElementById('itemSearchInput').value;
            const category = document.getElementById('categoryFilterSelect').value;
            
            let url = 'get_available_items.php';
            const params = new URLSearchParams();
            
            if (search) params.append('search', search);
            if (category) params.append('category', category);
            
            if (params.toString()) {
                url += '?' + params.toString();
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        availableItems = {
                            costumes: data.costumes,
                            equipment: data.equipment
                        };
                        displayAvailableItems();
                    } else {
                        console.error('Error loading available items:', data.message);
                        displayError('Error loading items: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    displayError('Error loading items: ' + error.message);
                });
        }

        function displayAvailableItems() {
            displayCostumeItems();
            displayEquipmentItems();
        }

        function displayCostumeItems() {
            const tbody = document.getElementById('availableCostumesBody');
            
            if (availableItems.costumes.length === 0) {
                tbody.innerHTML = `
                    <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                        <p>No available costumes found.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            availableItems.costumes.forEach(costume => {
                const isSelected = selectedItems.some(item => item.id === costume.id && item.type === 'costume');
                
                html += `
                    <div class="table-row" style="display: grid; grid-template-columns: 40px 1fr 120px 120px; padding: 0.75rem; border-bottom: 1px solid #e0e0e0; align-items: center;">
                        <div style="padding: 0 0.5rem;">
                            <input type="checkbox" value="${costume.id}" 
                                   ${isSelected ? 'checked' : ''}
                                   onchange="toggleItemSelection('costume', ${costume.id}, '${costume.name}', this.checked)">
                        </div>
                        <div style="padding: 0 0.5rem; font-weight: 500;">
                            ${costume.name}
                        </div>
                        <div style="padding: 0 0.5rem;">
                            <span class="condition-badge condition-${costume.condition}">${costume.condition}</span>
                        </div>
                        <div style="padding: 0 0.5rem;">
                            <span class="status-badge status-${costume.status}">${costume.status}</span>
                        </div>
                    </div>
                `;
            });

            tbody.innerHTML = html;
        }

        function displayEquipmentItems() {
            const tbody = document.getElementById('availableEquipmentBody');
            
            if (availableItems.equipment.length === 0) {
                tbody.innerHTML = `
                    <div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">
                        <p>No available equipment found.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            availableItems.equipment.forEach(equipment => {
                const isSelected = selectedItems.some(item => item.id === equipment.id && item.type === 'equipment');
                
                html += `
                    <div class="table-row" style="display: grid; grid-template-columns: 40px 1fr 120px 120px; padding: 0.75rem; border-bottom: 1px solid #e0e0e0; align-items: center;">
                        <div style="padding: 0 0.5rem;">
                            <input type="checkbox" value="${equipment.id}" 
                                   ${isSelected ? 'checked' : ''}
                                   onchange="toggleItemSelection('equipment', ${equipment.id}, '${equipment.name}', this.checked)">
                        </div>
                        <div style="padding: 0 0.5rem; font-weight: 500;">
                            ${equipment.name}
                        </div>
                        <div style="padding: 0 0.5rem;">
                            <span class="condition-badge condition-${equipment.condition}">${equipment.condition}</span>
                        </div>
                        <div style="padding: 0 0.5rem;">
                            <span class="status-badge status-${equipment.status}">${equipment.status}</span>
                        </div>
                    </div>
                `;
            });

            tbody.innerHTML = html;
        }

        function displayError(message) {
            const costumeBody = document.getElementById('availableCostumesBody');
            const equipmentBody = document.getElementById('availableEquipmentBody');
            
            const errorHtml = `
                <div class="empty-state" style="padding: 2rem; text-align: center; color: #dc2626;">
                    <p>${message}</p>
                </div>
            `;
            
            costumeBody.innerHTML = errorHtml;
            equipmentBody.innerHTML = errorHtml;
        }

        function toggleItemSelection(type, id, name, isChecked) {
            if (isChecked) {
                selectedItems.push({ type, id, name });
            } else {
                selectedItems = selectedItems.filter(item => !(item.type === type && item.id === id));
            }
            
            updateSelectionCounts();
            updateApproveButton();
        }

        function toggleSelectAllCostumes() {
            const selectAll = document.getElementById('selectAllCostumes');
            const checkboxes = document.querySelectorAll('#availableCostumesBody input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                const id = parseInt(checkbox.value);
                const costume = availableItems.costumes.find(c => c.id === id);
                
                if (costume) {
                    checkbox.checked = selectAll.checked;
                    toggleItemSelection('costume', id, costume.name, selectAll.checked);
                }
            });
        }

        function toggleSelectAllEquipment() {
            const selectAll = document.getElementById('selectAllEquipment');
            const checkboxes = document.querySelectorAll('#availableEquipmentBody input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                const id = parseInt(checkbox.value);
                const equipment = availableItems.equipment.find(e => e.id === id);
                
                if (equipment) {
                    checkbox.checked = selectAll.checked;
                    toggleItemSelection('equipment', id, equipment.name, selectAll.checked);
                }
            });
        }

        function updateSelectionCounts() {
            const costumeCount = selectedItems.filter(item => item.type === 'costume').length;
            const equipmentCount = selectedItems.filter(item => item.type === 'equipment').length;
            
            document.getElementById('selectedCostumesCount').textContent = `${costumeCount} selected`;
            document.getElementById('selectedEquipmentCount').textContent = `${equipmentCount} selected`;
        }

        function updateApproveButton() {
            const button = document.getElementById('approveButton');
            const totalSelected = selectedItems.length;
            
            if (totalSelected > 0) {
                button.disabled = false;
                button.textContent = `Approve Request (${totalSelected} items)`;
            } else {
                button.disabled = true;
                button.textContent = 'Approve Request (0 items)';
            }
        }

        function clearItemSelection() {
            selectedItems = [];
            updateSelectionCounts();
            updateApproveButton();
            
            // Clear checkboxes
            document.querySelectorAll('#itemSelectionModal input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
        }

        function searchAvailableItems() {
            loadAvailableItems();
        }

        function filterItemsByCategory() {
            loadAvailableItems();
        }

        function approveWithSelectedItems() {
            if (selectedItems.length === 0) {
                alert('Please select at least one item to approve.');
                return;
            }

            if (!currentRequestId) {
                alert('Error: No request selected.');
                return;
            }

            const confirmMessage = `Are you sure you want to approve this request with ${selectedItems.length} selected items?`;
            if (!confirm(confirmMessage)) {
                return;
            }

            // Send approval with selected items
            fetch('update_borrowing_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    request_id: currentRequestId,
                    action: 'approve',
                    selected_items: selectedItems
                })
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        alert('Request approved successfully!');
                        closeItemSelectionModal();
                        loadBorrowRequests(); // Refresh the requests list
                        loadInventoryItems(); // Refresh inventory to show items as "borrowed"
                    } else {
                        const errorMessage = data.message || data.error || 'Unknown error occurred';
                        alert('Error approving request: ' + errorMessage);
                    }
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    alert('Error: Invalid response from server');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error approving request: ' + error.message);
            });
        }

        // Load event participation data
        function loadEventParticipation() {
            console.log('Loading event participation data...');
            const loadingDiv = document.getElementById('participationLoading');
            const tableContainer = document.getElementById('participationTableContainer');
            // global variable to hold participation events for client-side filtering
            window.participationEvents = window.participationEvents || [];
            
            loadingDiv.style.display = 'block';
            tableContainer.style.display = 'none';
            
            fetch('get_event_participation.php')
                .then(response => {
                    console.log('Fetch response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Event participation data received:', data);
                    loadingDiv.style.display = 'none';
                    
                    if (data.success) {
                        console.log('Success! Events count:', data.events.length);
                        // store full events list for filtering
                        window.participationEvents = data.events || [];
                        displayEventParticipation(window.participationEvents);
                    } else {
                        console.error('API returned error:', data.message);
                        showParticipationError(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading event participation:', error);
                    loadingDiv.style.display = 'none';
                    showParticipationError('Error loading event participation: ' + error.message);
                });
        }

        // Display event participation table
        function displayEventParticipation(events) {
            const tableContainer = document.getElementById('participationTableContainer');
            
            if (events.length === 0) {
                tableContainer.innerHTML = `
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No events found</p>
                        <small>Events will appear here once created</small>
                    </div>
                `;
            } else {
                let html = `
                    <table style="width: 100%; border-collapse: collapse; background: white; margin: 0; table-layout: fixed;">
                        <colgroup>
                            <col style="width: 25%;">
                            <col style="width: 20%;">
                            <col style="width: 18%;">
                            <col style="width: 15%;">
                            <col style="width: 12%;">
                            <col style="width: 10%;">
                        </colgroup>
                        <thead style="background: #dc2626; color: white;">
                            <tr>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; font-style: normal;">Event Details</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; font-style: normal;">Date & Location</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; font-style: normal;">Cultural Groups</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; font-style: normal;">Participants</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; font-style: normal;">Status</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; font-style: normal;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                events.forEach(event => {
                    const dateRange = event.is_multi_day 
                        ? `${event.formatted_start_date} - ${event.formatted_end_date}`
                        : event.formatted_start_date;
                    
                    const statusBadge = getEventStatusBadge(event.date_status);
                    const culturalGroups = event.cultural_groups_array.slice(0, 2).join(', ');
                    const moreGroups = event.cultural_groups_array.length > 2 ? ` +${event.cultural_groups_array.length - 2} more` : '';
                    
                    html += `
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 1rem; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;">
                                <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem; font-style: normal; line-height: 1.3;">${event.title}</div>
                                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem; font-style: normal; font-weight: 500; line-height: 1.3;">${event.category}</div>
                                <div style="color: #888; font-size: 0.8rem; font-style: normal; font-weight: normal; line-height: 1.3;">Created: ${event.formatted_created_date}</div>
                            </td>
                            <td style="padding: 1rem; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;">
                                <div style="color: #333; margin-bottom: 0.25rem; font-style: normal; line-height: 1.3;">${dateRange}</div>
                                <div style="color: #666; font-size: 0.9rem; font-style: normal; line-height: 1.3;">${event.location}</div>
                            </td>
                            <td style="padding: 1rem; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;">
                                <div style="color: #333; font-size: 0.9rem; font-style: normal; line-height: 1.3;">${culturalGroups}${moreGroups}</div>
                            </td>
                            <td style="padding: 1rem; text-align: center; vertical-align: top;">
                                <span style="background: ${event.participants_count > 0 ? '#28a745' : '#6c757d'}; color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; font-style: normal; display: inline-block; min-width: fit-content; white-space: nowrap;">
                                    ${event.participants_count} participant(s)
                                </span>
                            </td>
                            <td style="padding: 1rem; text-align: center; vertical-align: top;">
                                ${statusBadge}
                            </td>
                            <td style="padding: 0.5rem; text-align: center; vertical-align: top;">
                                <div style="margin-top: 0.5rem;">
                                    <button onclick="viewEventParticipants(${event.id})" 
                                            style="background: #007bff; color: white; border: none; padding: 0.3rem 0.4rem; border-radius: 4px; cursor: pointer; font-size: 0.65rem; font-weight: 600; white-space: nowrap; width: 100%; line-height: 1.2;">
                                        View Participants
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                html += `
                        </tbody>
                    </table>
                `;
                tableContainer.innerHTML = html;
            }
            
            tableContainer.style.display = 'block';
            
            // Show and populate the chart
            showParticipationChart(events);
        }

        // Filter participation events client-side
        function filterParticipationEvents() {
            const q = document.getElementById('participationSearchInput').value.trim().toLowerCase();
            if (!window.participationEvents) return;
            if (q === '') {
                displayEventParticipation(window.participationEvents);
                return;
            }

            const filtered = window.participationEvents.filter(ev => {
                const title = (ev.title || '').toLowerCase();
                const category = (ev.category || '').toLowerCase();
                const location = (ev.location || '').toLowerCase();
                return title.includes(q) || category.includes(q) || location.includes(q);
            });

            displayEventParticipation(filtered);
        }

        function clearParticipationSearch() {
            const input = document.getElementById('participationSearchInput');
            if (input) {
                input.value = '';
                displayEventParticipation(window.participationEvents || []);
            }
        }

        // Chart functionality
        let chartEvents = [];
        let currentChartMode = 'all';
        let selectedEventId = null;

        function showParticipationChart(events) {
            chartEvents = events;
            const chartPanel = document.getElementById('participationChartPanel');
            
            // Safety check - only show chart if panel exists
            if (!chartPanel) {
                console.warn('Chart panel not found');
                return;
            }
            
            chartPanel.style.display = 'block';
            
            // Initialize chart with all events
            updateChart();
        }

        function setActiveChartButton(mode) {
            const allEventsBtn = document.getElementById('allEventsBtn');
            const individualEventBtn = document.getElementById('individualEventBtn');
            const selectedEventDisplay = document.getElementById('selectedEventDisplay');
            
            if (!allEventsBtn || !individualEventBtn) return;
            
            currentChartMode = mode;
            
            if (mode === 'all') {
                allEventsBtn.style.background = '#dc2626';
                allEventsBtn.style.color = 'white';
                allEventsBtn.style.borderColor = '#dc2626';
                allEventsBtn.classList.add('active');
                
                individualEventBtn.style.background = 'white';
                individualEventBtn.style.color = '#333';
                individualEventBtn.style.borderColor = '#ddd';
                individualEventBtn.classList.remove('active');
                
                selectedEventDisplay.style.display = 'none';
                selectedEventId = null;
            } else {
                allEventsBtn.style.background = 'white';
                allEventsBtn.style.color = '#333';
                allEventsBtn.style.borderColor = '#ddd';
                allEventsBtn.classList.remove('active');
                
                individualEventBtn.style.background = '#dc2626';
                individualEventBtn.style.color = 'white';
                individualEventBtn.style.borderColor = '#dc2626';
                individualEventBtn.classList.add('active');
                
                selectedEventDisplay.style.display = 'inline';
            }
        }

        function openEventSelectionModal() {
            const modal = document.getElementById('eventSelectionModal');
            const eventList = document.getElementById('eventSelectionList');
            
            if (!modal || !eventList) {
                console.warn('Event selection modal not found');
                return;
            }
            
            populateEventSelectionModal();
            modal.style.display = 'flex';
            preventBackgroundScroll();
        }

        function closeEventSelectionModal() {
            const modal = document.getElementById('eventSelectionModal');
            if (modal) {
                modal.style.display = 'none';
                allowBackgroundScroll();
                // Clear search input when closing modal
                const searchInput = document.getElementById('eventSearchInput');
                if (searchInput) {
                    searchInput.value = '';
                }
            }
        }
        
        function filterEvents() {
            const searchInput = document.getElementById('eventSearchInput');
            if (!searchInput) return;
            
            const searchValue = searchInput.value;
            populateEventSelectionModal(searchValue);
        }
        
        function generateAllEventsAnalytics(events) {
            const analyticsContainer = document.getElementById('chartAnalyticsContent');
            if (!analyticsContainer || events.length === 0) return;
            
            // Calculate analytics
            const totalEvents = events.length;
            const totalParticipants = events.reduce((sum, event) => sum + event.participants_count, 0);
            const totalEligible = events.reduce((sum, event) => sum + getTotalCulturalGroupMembers(event), 0);
            const avgParticipationRate = totalEligible > 0 ? ((totalParticipants / totalEligible) * 100) : 0;
            
            // Find best and worst performing events
            const eventRates = events.map(event => {
                const eligible = getTotalCulturalGroupMembers(event);
                const rate = eligible > 0 ? (event.participants_count / eligible) * 100 : 0;
                return { event, rate, participants: event.participants_count };
            });
            
            const bestEvent = eventRates.reduce((prev, current) => (prev.rate > current.rate) ? prev : current);
            const worstEvent = eventRates.reduce((prev, current) => (prev.rate < current.rate) ? prev : current);
            
            // Calculate trends
            const highPerformingEvents = eventRates.filter(e => e.rate >= 70).length;
            const mediumPerformingEvents = eventRates.filter(e => e.rate >= 40 && e.rate < 70).length;
            const lowPerformingEvents = eventRates.filter(e => e.rate < 40).length;
            
            // Generate HTML
            const analyticsHTML = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
                    <!-- Overall Statistics -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #28a745;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #28a745; font-size: 0.9rem; font-weight: 600;">📈 OVERALL PERFORMANCE</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>Total Events:</strong> ${totalEvents}</p>
                            <p style="margin: 0.25rem 0;"><strong>Total Participants:</strong> ${totalParticipants.toLocaleString()}</p>
                            <p style="margin: 0.25rem 0;"><strong>Avg. Participation Rate:</strong> ${avgParticipationRate.toFixed(1)}%</p>
                        </div>
                    </div>
                    
                    <!-- Best Performance -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #ffc107;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #ffc107; font-size: 0.9rem; font-weight: 600;">🏆 BEST PERFORMING</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>${bestEvent.event.title}</strong></p>
                            <p style="margin: 0.25rem 0;">${bestEvent.rate.toFixed(1)}% participation rate</p>
                            <p style="margin: 0.25rem 0;">${bestEvent.participants} participant(s)</p>
                        </div>
                    </div>
                    
                    <!-- Performance Distribution -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #6c757d;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #6c757d; font-size: 0.9rem; font-weight: 600;">📊 PERFORMANCE DISTRIBUTION</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>High (≥70%):</strong> ${highPerformingEvents} event(s)</p>
                            <p style="margin: 0.25rem 0;"><strong>Medium (40-69%):</strong> ${mediumPerformingEvents} event(s)</p>
                            <p style="margin: 0.25rem 0;"><strong>Low (<40%):</strong> ${lowPerformingEvents} event(s)</p>
                        </div>
                    </div>
                </div>
                
                <!-- Insights & Recommendations -->
                <div style="margin-top: 1rem; padding: 1rem; background: white; border-radius: 6px; border-left: 3px solid #17a2b8;">
                    <h5 style="margin: 0 0 0.5rem 0; color: #17a2b8; font-size: 0.9rem; font-weight: 600;">💡 KEY INSIGHTS</h5>
                    <div style="color: #333; font-size: 0.85rem; line-height: 1.5; font-style: normal;">
                        ${generateInsights(eventRates, avgParticipationRate, totalEvents)}
                    </div>
                </div>
            `;
            
            analyticsContainer.innerHTML = analyticsHTML;
        }
        
        function generateIndividualEventAnalytics(event) {
            const analyticsContainer = document.getElementById('chartAnalyticsContent');
            if (!analyticsContainer) return;
            
            const totalMembers = getTotalCulturalGroupMembers(event);
            const participants = event.participants_count;
            const participationRate = totalMembers > 0 ? (participants / totalMembers) * 100 : 0;
            const remainingCapacity = totalMembers - participants;
            
            // Simulate registration progress data
            const progressData = [
                Math.round(participants * 0.2), // Week 1
                Math.round(participants * 0.4), // Week 2  
                Math.round(participants * 0.7), // Week 3
                Math.round(participants * 0.9), // Week 4
                participants // Final
            ];
            
            const finalWeekGrowth = progressData[4] - progressData[3];
            const peakGrowthWeek = progressData.reduce((maxIdx, val, idx, arr) => {
                if (idx === 0) return 0;
                const growth = val - arr[idx - 1];
                const maxGrowth = arr[maxIdx] - (maxIdx > 0 ? arr[maxIdx - 1] : 0);
                return growth > maxGrowth ? idx : maxIdx;
            }, 1);
            
            // Determine status and performance level
            let statusColor, statusText, performanceLevel;
            if (participationRate >= 80) {
                statusColor = '#28a745';
                statusText = 'Excellent Participation';
                performanceLevel = 'High';
            } else if (participationRate >= 60) {
                statusColor = '#ffc107';
                statusText = 'Good Participation';
                performanceLevel = 'Medium';
            } else if (participationRate >= 40) {
                statusColor = '#fd7e14';
                statusText = 'Moderate Participation';
                performanceLevel = 'Medium';
            } else {
                statusColor = '#dc3545';
                statusText = 'Low Participation';
                performanceLevel = 'Low';
            }
            
            const analyticsHTML = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <!-- Event Overview -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid ${statusColor};">
                        <h5 style="margin: 0 0 0.5rem 0; color: ${statusColor}; font-size: 0.9rem; font-weight: 600;">🎯 EVENT OVERVIEW</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>Status:</strong> ${statusText}</p>
                            <p style="margin: 0.25rem 0;"><strong>Participation Rate:</strong> ${participationRate.toFixed(1)}%</p>
                            <p style="margin: 0.25rem 0;"><strong>Registered:</strong> ${participants}/${totalMembers}</p>
                        </div>
                    </div>
                    
                    <!-- Registration Progress -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #6f42c1;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #6f42c1; font-size: 0.9rem; font-weight: 600;">📈 REGISTRATION TRENDS</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>Peak Growth:</strong> Week ${peakGrowthWeek + 1}</p>
                            <p style="margin: 0.25rem 0;"><strong>Final Week Growth:</strong> +${finalWeekGrowth}</p>
                            <p style="margin: 0.25rem 0;"><strong>Early Adopters:</strong> ${progressData[0]} (${((progressData[0]/participants)*100).toFixed(0)}%)</p>
                        </div>
                    </div>
                    
                    <!-- Capacity Analysis -->
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #20c997;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #20c997; font-size: 0.9rem; font-weight: 600;">🎪 CAPACITY INSIGHTS</h5>
                        <div style="color: #333; font-size: 0.85rem; line-height: 1.4; font-style: normal;">
                            <p style="margin: 0.25rem 0;"><strong>Capacity Used:</strong> ${participationRate.toFixed(1)}%</p>
                            <p style="margin: 0.25rem 0;"><strong>Available Spots:</strong> ${remainingCapacity}</p>
                            <p style="margin: 0.25rem 0;"><strong>Performance Level:</strong> ${performanceLevel}</p>
                        </div>
                    </div>
                </div>
                
                <!-- Event-Specific Insights -->
                <div style="margin-top: 1rem; padding: 1rem; background: white; border-radius: 6px; border-left: 3px solid #e83e8c;">
                    <h5 style="margin: 0 0 0.5rem 0; color: #e83e8c; font-size: 0.9rem; font-weight: 600;">🔍 EVENT INSIGHTS</h5>
                    <div style="color: #333; font-size: 0.85rem; line-height: 1.5; font-style: normal;">
                        ${generateIndividualEventInsights(event, participationRate, progressData, remainingCapacity)}
                    </div>
                </div>
            `;
            
            analyticsContainer.innerHTML = analyticsHTML;
        }
        
        function generateInsights(eventRates, avgRate, totalEvents) {
            let insights = [];
            
            // Performance insights
            if (avgRate >= 70) {
                insights.push("🎉 <strong>Excellent overall engagement!</strong> Your events are performing well above average.");
            } else if (avgRate >= 50) {
                insights.push("👍 <strong>Good performance</strong> with room for improvement in some events.");
            } else {
                insights.push("⚠️ <strong>Low average participation.</strong> Consider reviewing event planning and promotion strategies.");
            }
            
            // Distribution insights
            const highCount = eventRates.filter(e => e.rate >= 70).length;
            const lowCount = eventRates.filter(e => e.rate < 40).length;
            
            if (highCount > totalEvents * 0.6) {
                insights.push("📈 <strong>Consistent high performance</strong> across most events indicates effective engagement strategies.");
            }
            
            if (lowCount > totalEvents * 0.3) {
                insights.push("🔍 <strong>Focus needed:</strong> Several events show low participation - consider targeted improvements.");
            }
            
            // Recommendations
            if (avgRate < 50) {
                insights.push("💡 <strong>Recommendations:</strong> Enhance promotion, adjust timing, or review event appeal to target audience.");
            }
            
            return insights.join('<br><br>');
        }
        
        function generateIndividualEventInsights(event, participationRate, progressData, remainingCapacity) {
            let insights = [];
            
            // Participation analysis
            if (participationRate >= 80) {
                insights.push("🌟 <strong>Outstanding participation!</strong> This event shows excellent community engagement.");
            } else if (participationRate >= 60) {
                insights.push("✅ <strong>Good participation rate.</strong> The event is well-received by the community.");
            } else if (participationRate >= 40) {
                insights.push("⚠️ <strong>Moderate engagement.</strong> Consider strategies to boost participation.");
            } else {
                insights.push("📉 <strong>Low participation.</strong> This event may need significant improvements or reconsideration.");
            }
            
            // Growth pattern analysis
            const finalGrowth = progressData[4] - progressData[3];
            if (finalGrowth > progressData[0]) {
                insights.push("🚀 <strong>Strong finale:</strong> Last-minute registrations exceeded early adoption, suggesting effective late-stage marketing.");
            } else if (finalGrowth < progressData[1] - progressData[0]) {
                insights.push("⏰ <strong>Early momentum:</strong> Most interest was captured early - consider extending early-bird promotions.");
            }
            
            // Capacity insights
            if (remainingCapacity > 0 && participationRate < 70) {
                insights.push(`📢 <strong>Growth opportunity:</strong> ${remainingCapacity} spots still available - consider additional promotion efforts.`);
            }
            
            return insights.join('<br><br>');
        }

        function populateEventSelectionModal(searchFilter = '') {
            const eventList = document.getElementById('eventSelectionList');
            if (!eventList) return;
            
            if (chartEvents.length === 0) {
                eventList.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No events available</p>';
                return;
            }
            
            // Filter events based on search input
            let filteredEvents = chartEvents;
            if (searchFilter.trim() !== '') {
                const searchLower = searchFilter.toLowerCase();
                filteredEvents = chartEvents.filter(event => {
                    return event.title.toLowerCase().includes(searchLower) ||
                           event.category.toLowerCase().includes(searchLower) ||
                           event.location.toLowerCase().includes(searchLower);
                });
            }
            
            if (filteredEvents.length === 0) {
                eventList.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No events match your search criteria</p>';
                return;
            }
            
            let html = '<div style="display: grid; gap: 0.5rem;">';
            filteredEvents.forEach(event => {
                const dateRange = event.is_multi_day 
                    ? `${event.formatted_start_date} - ${event.formatted_end_date}`
                    : event.formatted_start_date;
                
                const statusBadge = getEventStatusBadge(event.date_status);
                
                html += `
                    <div onclick="selectEventForChart(${event.id})" style="border: 1px solid #e0e0e0; border-radius: 6px; padding: 1rem; cursor: pointer; transition: all 0.2s; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <h4 style="margin: 0; color: #333; font-size: 1rem;">${event.title}</h4>
                            ${statusBadge}
                        </div>
                        <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">${event.category}</div>
                        <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">${dateRange}</div>
                        <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.5rem;">${event.location}</div>
                        <div style="display: flex; justify-content: between; align-items: center;">
                            <span style="background: ${event.participants_count > 0 ? '#28a745' : '#6c757d'}; color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                                ${event.participants_count} participant(s)
                            </span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            eventList.innerHTML = html;
            
            // Add hover effects
            eventList.querySelectorAll('div[onclick]').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.borderColor = '#dc2626';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                });
                item.addEventListener('mouseleave', function() {
                    this.style.borderColor = '#e0e0e0';
                    this.style.boxShadow = 'none';
                });
            });
        }

        function selectEventForChart(eventId) {
            const selectedEvent = chartEvents.find(e => e.id === eventId);
            if (!selectedEvent) return;
            
            selectedEventId = eventId;
            setActiveChartButton('individual');
            
            // Update selected event display
            const selectedEventDisplay = document.getElementById('selectedEventDisplay');
            if (selectedEventDisplay) {
                selectedEventDisplay.textContent = selectedEvent.title;
                selectedEventDisplay.style.color = '#dc2626';
                selectedEventDisplay.style.fontStyle = 'normal';
            }
            
            // Close modal and update chart
            closeEventSelectionModal();
            updateChart();
        }

        function updateChart() {
            if (currentChartMode === 'all') {
                drawAllEventsChart(chartEvents);
            } else if (currentChartMode === 'individual' && selectedEventId) {
                const selectedEvent = chartEvents.find(e => e.id === selectedEventId);
                if (selectedEvent) {
                    drawIndividualEventChart(selectedEvent);
                }
            } else {
                clearChart();
            }
        }

        function drawAllEventsChart(events) {
            var canvas = document.getElementById('participationChart');
            if (!canvas) {
                console.warn('Participation chart canvas not found');
                return;
            }
            
            // Destroy existing chart if any
            if (window.participationChartInstance) {
                window.participationChartInstance.destroy();
            }

            if (events.length === 0) {
                var ctx = canvas.getContext('2d');
                drawEmptyChart(ctx, canvas, 'No events available');
                return;
            }

            // Prepare data for Chart.js
            var chartData = events.map(function(event) {
                var eligibleMembers = getTotalCulturalGroupMembers(event);
                var participationRate = eligibleMembers > 0 ? (event.participants_count / eligibleMembers) * 100 : 0;
                return {
                    x: event.title.length > 15 ? event.title.substring(0, 13) + '...' : event.title,
                    y: parseFloat(participationRate.toFixed(1)),
                    fullTitle: event.title,
                    participants: event.participants_count || 0,
                    eligible: eligibleMembers,
                    date: event.start_date || 'N/A'
                };
            });

            var chartLabels = chartData.map(function(item) { return item.x; });
            var chartValues = chartData.map(function(item) { return item.y; });

            // Create interactive Chart.js line chart
            var ctx = canvas.getContext('2d');
            window.participationChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Participation Rate (%)',
                        data: chartValues,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 8,
                        pointHoverRadius: 12,
                        pointBackgroundColor: '#dc2626',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 3,
                        pointHoverBorderWidth: 4,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1200,
                        easing: 'easeInOutCubic'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                color: '#666',
                                font: {
                                    size: 12
                                }
                            },
                            title: {
                                display: true,
                                text: 'Participation Rate (%)',
                                color: '#333',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            ticks: {
                                color: '#666',
                                font: {
                                    size: 11
                                },
                                maxRotation: 45
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Event Participation Trend',
                            color: '#333',
                            font: {
                                size: 18,
                                weight: 'bold'
                            },
                            padding: 20
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                color: '#666',
                                font: {
                                    size: 13
                                }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(255, 255, 255, 0.96)',
                            titleColor: '#333',
                            bodyColor: '#666',
                            borderColor: '#dc2626',
                            borderWidth: 2,
                            cornerRadius: 12,
                            padding: 16,
                            titleFont: {
                                weight: 'bold',
                                size: 15
                            },
                            bodyFont: {
                                size: 14
                            },
                            displayColors: true,
                            usePointStyle: true,
                            callbacks: {
                                title: function(tooltipItems) {
                                    var index = tooltipItems[0].dataIndex;
                                    return chartData[index].fullTitle;
                                },
                                label: function(context) {
                                    var index = context.dataIndex;
                                    var data = chartData[index];
                                    return [
                                        'Participation Rate: ' + data.y + '%',
                                        'Participants: ' + data.participants,
                                        'Event Date: ' + (data.date !== 'N/A' ? new Date(data.date).toLocaleDateString() : 'Not specified')
                                    ];
                                }
                            }
                        }
                    },
                    onHover: function(event, activeElements) {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    }
                }
            });

            // Generate analytics
            generateAllEventsAnalytics(events);
        }

        function drawPieChartLegend(events, colors, totalParticipants) {
            var legendContainer = document.getElementById('chartLegendContainer');
            if (!legendContainer) return;
            
            var legendHTML = '';
            events.forEach(function(event, index) {
                if (event.participants_count > 0) {
                    var color = colors[index % colors.length];
                    var percentage = ((event.participants_count / totalParticipants) * 100).toFixed(1);
                    legendHTML += 
                        '<div style="display: flex; align-items: center; gap: 0.5rem; margin: 0.25rem;">' +
                            '<div style="width: 16px; height: 16px; background: ' + color + '; border-radius: 3px;"></div>' +
                            '<span style="font-size: 12px; color: #333;">' + event.title + ' (' + event.participants_count + ' - ' + percentage + '%)</span>' +
                        '</div>';
                }
            });
            legendContainer.innerHTML = legendHTML;
        }

        function drawIndividualEventChart(event) {
            var canvas = document.getElementById('participationChart');
            if (!canvas) {
                console.warn('Chart canvas not found');
                return;
            }
            
            // Destroy existing chart if any
            if (window.participationChartInstance) {
                window.participationChartInstance.destroy();
            }
            
            if (!event) {
                var ctx = canvas.getContext('2d');
                drawEmptyChart(ctx, canvas, 'No event data available');
                return;
            }

            var culturalGroups = event.cultural_groups_array || ['All Groups'];
            var totalMembers = getTotalCulturalGroupMembers(event);
            var participants = event.participants_count;

            if (totalMembers === 0) {
                var ctx = canvas.getContext('2d');
                drawEmptyChart(ctx, canvas, 'No eligible members data available');
                return;
            }

            // Prepare data for Chart.js pie chart
            var participationRate = (participants / totalMembers) * 100;
            var nonParticipationRate = 100 - participationRate;

            var chartData = {
                labels: ['Participants', 'Non-Participants'],
                datasets: [{
                    data: [participants, totalMembers - participants],
                    backgroundColor: ['#dc2626', '#e5e7eb'],
                    borderColor: ['#dc2626', '#9ca3af'],
                    borderWidth: 2
                }]
            };

            // Create Chart.js pie chart
            var ctx = canvas.getContext('2d');
            window.participationChartInstance = new Chart(ctx, {
                type: 'pie',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutCubic'
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: event.title + ' - Participation Overview',
                            color: '#333',
                            font: {
                                size: 16,
                                weight: 'bold'
                            },
                            padding: 20
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                color: '#666',
                                font: {
                                    size: 13
                                }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(255, 255, 255, 0.96)',
                            titleColor: '#333',
                            bodyColor: '#666',
                            borderColor: '#dc2626',
                            borderWidth: 2,
                            cornerRadius: 12,
                            padding: 16,
                            titleFont: {
                                weight: 'bold',
                                size: 15
                            },
                            bodyFont: {
                                size: 14
                            },
                            callbacks: {
                                label: function(context) {
                                    var label = context.label;
                                    var value = context.raw;
                                    var percentage = ((value / totalMembers) * 100).toFixed(1);
                                    return [
                                        label + ': ' + value + ' members',
                                        'Percentage: ' + percentage + '%',
                                        'Event: ' + event.title,
                                        'Total Eligible: ' + totalMembers + ' members'
                                    ];
                                }
                            }
                        }
                    },
                    onHover: function(event, activeElements) {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    }
                }
            });

            // Generate analytics for individual event
            generateIndividualEventAnalytics(event);
        }

        function getTotalCulturalGroupMembers(event) {
            // This is a placeholder function
            // In a real implementation, you would fetch this data from the server
            // based on the event's cultural groups
            
            // For demonstration, we'll use a simple calculation
            // This should be replaced with actual data from your database
            
            if (!event.cultural_groups_array || event.cultural_groups_array.length === 0) {
                // If no specific groups, assume total student population
                return Math.max(event.participants_count * 3, 50); // Estimate
            }
            
            // Estimate based on cultural groups
            // You should replace this with actual queries to your database
            const estimatedMembersPerGroup = 25; // Average members per cultural group
            return event.cultural_groups_array.length * estimatedMembersPerGroup;
        }

        function drawEmptyChart(ctx, canvas) {
            ctx.fillStyle = '#666';
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('No events available', canvas.width / 2, canvas.height / 2);
        }

        function clearChart() {
            const canvas = document.getElementById('participationChart');
            
            // Safety check
            if (!canvas) {
                console.warn('Chart canvas not found');
                return;
            }
            
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            ctx.fillStyle = '#666';
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Select an event to view details', canvas.width / 2, canvas.height / 2);
            
            // Clear legend
            const legendContainer = document.getElementById('chartLegendContainer');
            if (legendContainer) {
                legendContainer.innerHTML = '';
            }
            
            // Clear analytics
            const analyticsContainer = document.getElementById('chartAnalyticsContent');
            if (analyticsContainer) {
                analyticsContainer.innerHTML = '<p style="color: #666; margin: 0;">Select a chart view to see detailed analytics</p>';
            }
        }


        // Get event status badge
        function getEventStatusBadge(dateStatus) {
            switch(dateStatus) {
                case 'upcoming':
                    return '<span style="background: #007bff; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; font-style: normal;">Upcoming</span>';
                case 'ongoing':
                    return '<span style="background: #28a745; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; font-style: normal;">Ongoing</span>';
                case 'completed':
                    return '<span style="background: #6c757d; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; font-style: normal;">Completed</span>';
                default:
                    return '<span style="background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; font-style: normal;">Unknown</span>';
            }
        }

        // Show participation error
        function showParticipationError(message) {
            const tableContainer = document.getElementById('participationTableContainer');
            tableContainer.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: #dc2626;">
                    <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Error loading participation data</p>
                    <small>${message}</small>
                </div>
            `;
            tableContainer.style.display = 'block';
        }

        // View event participants
        function viewEventParticipants(eventId) {
            const modal = document.getElementById('eventParticipantsModal');
            const loadingDiv = document.getElementById('participantsLoading');
            const contentDiv = document.getElementById('participantsContent');
            
            // Clear previous content immediately
            document.getElementById('participantsModalTitle').textContent = 'Loading...';
            document.getElementById('eventDetailsContent').innerHTML = '';
            document.getElementById('participantsTableBody').innerHTML = '';
            document.getElementById('participantsCount').textContent = '0 participants';
            
            // Hide content and show loading
            modal.classList.add('show');
            modal.style.display = 'flex';
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Prevent background scrolling
            document.body.style.overflow = 'hidden';
            
            fetch(`get_event_participants.php?event_id=${eventId}`)
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    
                    if (data.success) {
                        displayEventParticipants(data.event, data.participants);
                        contentDiv.style.display = 'block';
                    } else {
                        showParticipantsError(data.message);
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    showParticipantsError('Error loading participants: ' + error.message);
                });
        }

        // Store all participants globally for filtering
        let allParticipantsData = [];
        let currentEvent = null;

        // Display event participants
        function displayEventParticipants(event, participants) {
            // Store data globally
            currentEvent = event;
            allParticipantsData = participants;
            
            // Update modal title and event details
            document.getElementById('participantsModalTitle').textContent = `Participants - ${event.title}`;
            
            const dateRange = event.is_multi_day 
                ? `${event.formatted_start_date} - ${event.formatted_end_date}`
                : event.formatted_start_date;
            
            document.getElementById('eventDetailsContent').innerHTML = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <strong style="color: #333;">Event:</strong>
                        <div style="color: #666;">${event.title}</div>
                    </div>
                    <div>
                        <strong style="color: #333;">Date:</strong>
                        <div style="color: #666;">${dateRange}</div>
                    </div>
                    <div>
                        <strong style="color: #333;">Location:</strong>
                        <div style="color: #666;">${event.location}</div>
                    </div>
                    <div>
                        <strong style="color: #333;">Category:</strong>
                        <div style="color: #666;">${event.category}</div>
                    </div>
                </div>
            `;
            
            // Setup search functionality
            const searchInput = document.getElementById('participantsSearchInput');
            searchInput.value = '';
            searchInput.onkeyup = function() {
                filterParticipants(this.value);
            };
            
            // Display all participants initially
            renderParticipantsTable(participants);
        }
        
        // Filter participants based on search
        function filterParticipants(searchTerm) {
            const term = searchTerm.toLowerCase().trim();
            
            if (!term) {
                renderParticipantsTable(allParticipantsData);
                return;
            }
            
            const filtered = allParticipantsData.filter(p => {
                return (p.full_name && p.full_name.toLowerCase().includes(term)) ||
                       (p.display_sr_code && p.display_sr_code.toLowerCase().includes(term)) ||
                       (p.display_email && p.display_email.toLowerCase().includes(term)) ||
                       (p.cultural_group && p.cultural_group.toLowerCase().includes(term));
            });
            
            renderParticipantsTable(filtered);
        }
        
        // Render participants table
        function renderParticipantsTable(participants) {
            // Update participants count
            document.getElementById('participantsCount').textContent = `${participants.length} participant(s)`;
            
            const tableBody = document.getElementById('participantsTableBody');
            const noParticipantsMsg = document.getElementById('noParticipantsMessage');
            const participantsSummary = document.querySelector('.participants-summary');
            const tableContainer = document.querySelector('.table-container');
            
            // Clear table body first
            tableBody.innerHTML = '';
            
            if (participants.length === 0) {
                const searchInput = document.getElementById('participantsSearchInput');
                if (searchInput.value.trim()) {
                    // Show "no results" for search
                    tableContainer.style.display = 'block';
                    participantsSummary.style.display = 'flex';
                    noParticipantsMsg.style.display = 'none';
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="4" style="padding: 3rem; text-align: center; color: #666;">
                                <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No participants found</p>
                                <small>Try a different search term</small>
                            </td>
                        </tr>
                    `;
                } else {
                    // No participants at all
                    participantsSummary.style.display = 'none';
                    tableContainer.style.display = 'none';
                    noParticipantsMsg.style.display = 'block';
                }
            } else {
                participantsSummary.style.display = 'flex';
                tableContainer.style.display = 'block';
                noParticipantsMsg.style.display = 'none';
                
                let html = '';
                participants.forEach((participant, index) => {
                    const academicInfo = [participant.display_program, participant.display_year, participant.display_campus].filter(Boolean).join(' • ');
                    
                    html += `
                        <tr style="border-bottom: 1px solid #e0e0e0; ${index % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                            <td style="padding: 1rem; vertical-align: top;">
                                <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">${participant.full_name}</div>
                                <div style="color: #666; font-size: 0.9rem; margin-bottom: 0.25rem;">${participant.display_sr_code || 'N/A'}</div>
                                <div style="color: #666; font-size: 0.8rem;">${participant.display_email || 'N/A'}</div>
                            </td>
                            <td style="padding: 1rem; vertical-align: top;">
                                <div style="color: #333; font-weight: 500;">${participant.cultural_group || 'Not specified'}</div>
                            </td>
                            <td style="padding: 1rem; vertical-align: top;">
                                <div style="color: #333; font-size: 0.9rem;">${academicInfo || 'Not specified'}</div>
                                ${participant.college ? `<div style="color: #666; font-size: 0.8rem;">${participant.college}</div>` : ''}
                            </td>
                            <td style="padding: 1rem; vertical-align: top;">
                                <div style="color: #333; font-size: 0.9rem;">${participant.formatted_registration_date}</div>
                            </td>
                        </tr>
                    `;
                });
                
                tableBody.innerHTML = html;
            }
        }

        // Show participants error
        function showParticipantsError(message) {
            document.getElementById('participantsLoading').style.display = 'none';
            document.getElementById('participantsContent').innerHTML = `
                <div style="text-align: center; padding: 3rem; color: #dc2626;">
                    <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Error loading participants</p>
                    <small>${message}</small>
                </div>
            `;
            document.getElementById('participantsContent').style.display = 'block';
        }

        // Close participants modal
        function closeParticipantsModal() {
            const modal = document.getElementById('eventParticipantsModal');
            modal.classList.remove('show');
            modal.style.display = 'none';
            
            // Restore background scrolling
            document.body.style.overflow = '';
            
            // Clear modal content
            document.getElementById('participantsModalTitle').textContent = 'Event Participants';
            document.getElementById('eventDetailsContent').innerHTML = '';
            document.getElementById('participantsTableBody').innerHTML = '';
            document.getElementById('participantsCount').textContent = '0 participants';
            document.getElementById('participantsSearchInput').value = '';
            
            // Reset global data
            allParticipantsData = [];
            currentEvent = null;
            
            // Reset display states
            document.getElementById('participantsLoading').style.display = 'none';
            document.getElementById('participantsContent').style.display = 'none';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('eventParticipantsModal');
            if (event.target === modal) {
                closeParticipantsModal();
            }
            
            // Close event selection modal when clicking outside
            const eventSelectionModal = document.getElementById('eventSelectionModal');
            if (event.target === eventSelectionModal) {
                closeEventSelectionModal();
            }
            
            // Close event evaluation selection modal when clicking outside
            const eventEvaluationSelectionModal = document.getElementById('eventEvaluationSelectionModal');
            if (event.target === eventEvaluationSelectionModal) {
                closeEventEvaluationSelectionModal();
            }
        });

        // Event Evaluations Analytics Functions
        let evaluationData = [];
        let evaluationEvents = [];
        let currentEvaluationMode = 'all';

        // Initialize evaluation analytics when reports section is active
        let evaluationAnalyticsInitialized = false;
        function initializeEvaluationAnalytics() {
            if (evaluationAnalyticsInitialized) {
                console.log('Evaluation analytics already initialized, just loading data...');
                loadEvaluationAnalytics();
                return;
            }
            
            console.log('Initializing evaluation analytics...');
            const allEvaluationsBtn = document.getElementById('allEvaluationsBtn');
            const individualEventEvalBtn = document.getElementById('individualEventEvalBtn');

            console.log('Buttons found:', {
                allEvaluationsBtn: !!allEvaluationsBtn,
                individualEventEvalBtn: !!individualEventEvalBtn
            });

            // Button event listeners
            if (allEvaluationsBtn) {
                allEvaluationsBtn.addEventListener('click', () => {
                    console.log('All evaluations clicked');
                    setEvaluationMode('all');
                });
            }
            if (individualEventEvalBtn) {
                individualEventEvalBtn.addEventListener('click', () => {
                    console.log('Individual event clicked - opening modal');
                    setEvaluationMode('individual');
                    openEventEvaluationSelectionModal();
                });
            }

            evaluationAnalyticsInitialized = true;
            loadEvaluationAnalytics();
        }

        function setEvaluationMode(mode) {
            console.log('Setting evaluation mode to:', mode);
            currentEvaluationMode = mode;
            const allBtn = document.getElementById('allEvaluationsBtn');
            const individualBtn = document.getElementById('individualEventEvalBtn');

            console.log('Mode buttons found:', {
                allBtn: !!allBtn,
                individualBtn: !!individualBtn
            });

            // Update button styles and selected event display
            const selectedEventDisplay = document.getElementById('selectedEvaluationEventDisplay');
            
            if (mode === 'all') {
                allBtn.style.background = '#dc2626';
                allBtn.style.color = 'white';
                allBtn.style.borderColor = '#dc2626';
                individualBtn.style.background = 'white';
                individualBtn.style.color = '#333';
                individualBtn.style.borderColor = '#ddd';
                
                // Hide selected event display for all mode
                if (selectedEventDisplay) {
                    selectedEventDisplay.style.display = 'none';
                }
            } else {
                individualBtn.style.background = '#dc2626';
                individualBtn.style.color = 'white';
                individualBtn.style.borderColor = '#dc2626';
                allBtn.style.background = 'white';
                allBtn.style.color = '#333';
                allBtn.style.borderColor = '#ddd';
                
                // Show selected event display for individual mode
                if (selectedEventDisplay && window.selectedEvaluationEventTitle) {
                    selectedEventDisplay.textContent = window.selectedEvaluationEventTitle;
                    selectedEventDisplay.style.display = 'inline';
                } else if (selectedEventDisplay) {
                    selectedEventDisplay.textContent = 'No event selected';
                    selectedEventDisplay.style.display = 'inline';
                }
            }

            loadEvaluationAnalytics();
        }

        function loadEvaluationAnalytics() {
            console.log('Loading evaluation analytics...');
            var loadingDiv = document.getElementById('evaluationAnalyticsLoading');
            var contentDiv = document.getElementById('evaluationAnalyticsContent');

            if (loadingDiv) {
                loadingDiv.style.display = 'block';
            }
            if (contentDiv) {
                contentDiv.style.display = 'none';
            }

            var params = new URLSearchParams({
                mode: currentEvaluationMode || 'all'
            });

            if (currentEvaluationMode === 'individual') {
                var selectedEventId = window.selectedEvaluationEventId;
                if (selectedEventId) {
                    params.append('event_id', selectedEventId);
                }
            }

            console.log('Fetching evaluation analytics with params:', params.toString());
            
            fetch('get_evaluation_analytics.php?' + params.toString())
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    console.log('Evaluation analytics response:', data);
                    if (loadingDiv) {
                        loadingDiv.style.display = 'none';
                    }
                    
                    if (data.success) {
                        evaluationData = data.evaluations;
                        evaluationEvents = data.events;
                        displayEvaluationAnalytics(data);
                        if (contentDiv) {
                            contentDiv.style.display = 'block';
                        }
                    } else {
                        console.error('Evaluation analytics API error:', data.message);
                        showEvaluationError(data.message);
                    }
                })
                .catch(function(error) {
                    console.error('Evaluation analytics fetch error:', error);
                    if (loadingDiv) {
                        loadingDiv.style.display = 'none';
                    }
                    showEvaluationError('Error loading evaluation analytics: ' + error.message);
                });
        }



        function displayEvaluationAnalytics(data) {
            // Update statistics cards if they exist
            var statsElements = [
                { id: 'totalEvaluations', value: data.statistics.total_evaluations },
                { id: 'averageRating', value: data.statistics.average_rating.toFixed(1) },
                { id: 'responseRate', value: data.statistics.response_rate + '%' },
                { id: 'satisfactionScore', value: data.statistics.satisfaction_score + '%' }
            ];

            statsElements.forEach(function(stat) {
                var element = document.getElementById(stat.id);
                if (element) {
                    element.textContent = stat.value;
                }
            });

            // Draw charts with staggered timing for smooth loading
            setTimeout(function() {
                drawQuestionScoresChart(data.question_scores);
            }, 100);
            
            setTimeout(function() {
                drawEvaluationTrendsChart(data.trends);
            }, 300);

            // Display detailed insights
            setTimeout(function() {
                displayEvaluationInsights(data.insights);
            }, 500);

            // Display comments analysis
            setTimeout(function() {
                displayCommentsAnalysis(data.comments);
            }, 700);
        }

        function drawRatingDistributionChart(ratingData) {
            var canvas = document.getElementById('ratingDistributionChart');
            if (!canvas) {
                console.warn('Rating distribution canvas not found');
                return;
            }
            
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!ratingData || ratingData.length === 0) {
                drawEmptyChart(ctx, canvas);
                return;
            }

            // Chart setup
            var padding = 50;
            var chartWidth = canvas.width - (padding * 2);
            var chartHeight = canvas.height - (padding * 2);
            var barWidth = chartWidth / ratingData.length;

            // Find max value for scaling
            var maxValue = Math.max.apply(Math, ratingData.map(function(d) { return d.count; }));

            // Draw bars
            ratingData.forEach(function(data, index) {
                var barHeight = (data.count / maxValue) * chartHeight;
                var x = padding + (index * barWidth);
                var y = padding + chartHeight - barHeight;

                // Draw bar
                ctx.fillStyle = 'hsl(' + (120 - (data.rating * 12)) + ', 70%, 50%)';
                ctx.fillRect(x + 5, y, barWidth - 10, barHeight);

                // Draw rating label
                ctx.fillStyle = '#333';
                ctx.font = '12px Arial';
                ctx.textAlign = 'center';
                ctx.fillText(data.rating + '★', x + barWidth/2, canvas.height - 20);

                // Draw count label
                ctx.fillText(data.count, x + barWidth/2, y - 5);
            });

            // Draw title
            ctx.fillStyle = '#333';
            ctx.font = 'bold 14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Rating Distribution', canvas.width / 2, 25);
        }

        function drawQuestionScoresChart(questionData) {
            var canvas = document.getElementById('questionScoresChart');
            if (!canvas) {
                console.warn('Question scores canvas not found');
                return;
            }
            
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!questionData || questionData.length === 0) {
                drawEmptyChart(ctx, canvas, 'No question data available');
                return;
            }

            // Chart setup
            var padding = 60;
            var paddingTop = 40;
            var chartWidth = canvas.width - (padding * 2);
            var chartHeight = canvas.height - paddingTop - 20;
            var barHeight = 18;
            // Calculate dynamic spacing to fit all questions
            var totalBars = questionData.length;
            var barSpacing = chartHeight / totalBars;

            // Draw horizontal bars
            questionData.forEach(function(question, index) {
                var barWidth = (question.average_score / 5) * chartWidth;
                var y = paddingTop + (index * barSpacing);

                // Draw bar background
                ctx.fillStyle = '#f0f0f0';
                ctx.fillRect(padding, y + 15, chartWidth, barHeight);

                // Draw bar
                var hue = (question.average_score / 5) * 120; // Green for high scores
                ctx.fillStyle = 'hsl(' + hue + ', 70%, 50%)';
                ctx.fillRect(padding, y + 15, barWidth, barHeight);

                // Draw question label
                ctx.fillStyle = '#333';
                ctx.font = '11px Arial';
                ctx.textAlign = 'left';
                var questionText = question.question.length > 45 ? 
                    question.question.substring(0, 45) + '...' : question.question;
                ctx.fillText(questionText, padding, y + 12);

                // Draw score
                ctx.font = 'bold 12px Arial';
                ctx.textAlign = 'right';
                ctx.fillText(question.average_score.toFixed(1), canvas.width - padding + 10, y + 28);
            });

            // Draw title
            ctx.fillStyle = '#333';
            ctx.font = 'bold 14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Average Question Scores', canvas.width / 2, 20);
        }

        function drawEvaluationTrendsChart(trendsData) {
            var canvas = document.getElementById('evaluationTrendsChart');
            if (!canvas) {
                console.warn('Evaluation trends canvas not found');
                return;
            }
            
            // Destroy existing chart if any
            if (window.evaluationTrendsChartInstance) {
                window.evaluationTrendsChartInstance.destroy();
            }

            if (!trendsData || trendsData.length === 0) {
                var ctx = canvas.getContext('2d');
                drawEmptyChart(ctx, canvas, 'No evaluation trends data available');
                return;
            }

            // Prepare data for Chart.js
            var labels = trendsData.map(function(item) {
                return item.period || 'Period ' + (trendsData.indexOf(item) + 1);
            });
            var data = trendsData.map(function(item) { return parseFloat(item.average_rating.toFixed(2)); });

            // Create interactive Chart.js line chart
            var ctx = canvas.getContext('2d');
            window.evaluationTrendsChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Average Rating',
                        data: data,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 8,
                        pointHoverRadius: 12,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 3,
                        pointHoverBorderWidth: 4,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    resizeDelay: 200,
                    animation: {
                        duration: 1200,
                        easing: 'easeInOutCubic'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        y: {
                            min: 1,
                            max: 5,
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            ticks: {
                                stepSize: 0.5,
                                color: '#666',
                                font: {
                                    size: 12
                                },
                                callback: function(value) {
                                    return value.toFixed(1);
                                }
                            },
                            title: {
                                display: true,
                                text: 'Average Rating',
                                color: '#333',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            ticks: {
                                color: '#666',
                                font: {
                                    size: 12
                                }
                            },
                            title: {
                                display: true,
                                text: 'Time Period',
                                color: '#333',
                                font: {
                                    size: 13,
                                    weight: 'bold'
                                }
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Evaluation Trends Over Time',
                            color: '#333',
                            font: {
                                size: 16,
                                weight: 'bold'
                            },
                            padding: 20
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                color: '#666',
                                font: {
                                    size: 13
                                }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(255, 255, 255, 0.96)',
                            titleColor: '#333',
                            bodyColor: '#666',
                            borderColor: '#10b981',
                            borderWidth: 2,
                            cornerRadius: 12,
                            padding: 16,
                            titleFont: {
                                weight: 'bold',
                                size: 15
                            },
                            bodyFont: {
                                size: 14
                            },
                            displayColors: true,
                            usePointStyle: true,
                            callbacks: {
                                title: function(tooltipItems) {
                                    var index = tooltipItems[0].dataIndex;
                                    return 'Period: ' + labels[index];
                                },
                                label: function(context) {
                                    var index = context.dataIndex;
                                    var rating = trendsData[index].average_rating;
                                    var responses = trendsData[index].total_responses || 'Unknown';
                                    var trend = '';
                                    
                                    if (index > 0) {
                                        var previousRating = trendsData[index - 1].average_rating;
                                        var change = rating - previousRating;
                                        if (change > 0) {
                                            trend = ' (↑' + change.toFixed(2) + ')';
                                        } else if (change < 0) {
                                            trend = ' (↓' + Math.abs(change).toFixed(2) + ')';
                                        } else {
                                            trend = ' (→ no change)';
                                        }
                                    }
                                    
                                    return [
                                        'Average Rating: ' + rating.toFixed(2) + ' / 5.0' + trend,
                                        'Total Responses: ' + responses,
                                        'Performance: ' + (rating >= 4.0 ? 'Excellent' : rating >= 3.0 ? 'Good' : rating >= 2.0 ? 'Fair' : 'Needs Improvement')
                                    ];
                                }
                            }
                        }
                    },
                    onHover: function(event, activeElements) {
                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                    }
                }
            });
        }

        function displayEvaluationInsights(insights) {
            var container = document.getElementById('evaluationInsightsContent');
            
            if (!insights) {
                container.innerHTML = '<p style="color: #666; margin: 0;">No insights available</p>';
                return;
            }

            var html = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">';

            // Overall Performance
            if (insights.overall) {
                html += `
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #28a745;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #28a745; font-size: 0.9rem;">📊 Overall Performance</h5>
                        <p style="margin: 0; color: #666; font-size: 0.85rem; line-height: 1.4;">${insights.overall}</p>
                    </div>
                `;
            }

            // Strengths
            if (insights.strengths) {
                html += `
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #ffc107;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #ffc107; font-size: 0.9rem;">💪 Key Strengths</h5>
                        <p style="margin: 0; color: #666; font-size: 0.85rem; line-height: 1.4;">${insights.strengths}</p>
                    </div>
                `;
            }



            // Trends
            if (insights.trends) {
                html += `
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #17a2b8;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #17a2b8; font-size: 0.9rem;">📈 Trends</h5>
                        <p style="margin: 0; color: #666; font-size: 0.85rem; line-height: 1.4;">${insights.trends}</p>
                    </div>
                `;
            }

            // Recommendations
            if (insights.recommendations) {
                html += `
                    <div style="background: white; padding: 1rem; border-radius: 6px; border-left: 3px solid #6f42c1;">
                        <h5 style="margin: 0 0 0.5rem 0; color: #6f42c1; font-size: 0.9rem;">💡 Recommendations</h5>
                        <p style="margin: 0; color: #666; font-size: 0.85rem; line-height: 1.4;">${insights.recommendations}</p>
                    </div>
                `;
            }

            html += '</div>';
            container.innerHTML = html;
        }

        function displayCommentsAnalysis(comments) {
            const positiveContainer = document.getElementById('positiveComments');
            const improvementContainer = document.getElementById('improvementComments');

            // Display positive comments
            if (comments.positive && comments.positive.length > 0) {
                let positiveHtml = '';
                comments.positive.forEach(comment => {
                    positiveHtml += `
                        <div style="background: #f8f9fa; padding: 0.75rem; border-radius: 4px; margin-bottom: 0.5rem; border-left: 3px solid #28a745;">
                            <p style="margin: 0 0 0.5rem 0; color: #333; font-size: 0.85rem; line-height: 1.4;">"${comment.text}"</p>
                            <small style="color: #666;">- ${comment.event_title} (${comment.date})</small>
                        </div>
                    `;
                });
                positiveContainer.innerHTML = positiveHtml;
            } else {
                positiveContainer.innerHTML = '<p style="color: #666; font-style: italic; text-align: center; padding: 1rem;">No positive comments available</p>';
            }

            // Display improvement comments
            if (comments.improvement && comments.improvement.length > 0) {
                let improvementHtml = '';
                comments.improvement.forEach(comment => {
                    improvementHtml += `
                        <div style="background: #f8f9fa; padding: 0.75rem; border-radius: 4px; margin-bottom: 0.5rem; border-left: 3px solid #ffc107;">
                            <p style="margin: 0 0 0.5rem 0; color: #333; font-size: 0.85rem; line-height: 1.4;">"${comment.text}"</p>
                            <small style="color: #666;">- ${comment.event_title} (${comment.date})</small>
                        </div>
                    `;
                });
                improvementContainer.innerHTML = improvementHtml;
            } else {
                improvementContainer.innerHTML = '<p style="color: #666; font-style: italic; text-align: center; padding: 1rem;">No improvement suggestions available</p>';
            }
        }

        function showEvaluationError(message) {
            const contentDiv = document.getElementById('evaluationAnalyticsContent');
            contentDiv.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: #dc2626;">
                    <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Error loading evaluation analytics</p>
                    <small>${message}</small>
                </div>
            `;
            contentDiv.style.display = 'block';
        }

        // Event Evaluation Selection Modal Functions
        function openEventEvaluationSelectionModal() {
            const modal = document.getElementById('eventEvaluationSelectionModal');
            const eventList = document.getElementById('eventEvaluationSelectionList');
            
            if (!modal || !eventList) {
                console.error('Event evaluation selection modal elements not found');
                return;
            }
            
            modal.style.display = 'flex';
            populateEventEvaluationSelectionModal();
            preventBackgroundScroll();
        }

        function closeEventEvaluationSelectionModal() {
            const modal = document.getElementById('eventEvaluationSelectionModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        function filterEvaluationEvents() {
            const searchInput = document.getElementById('eventEvaluationSearchInput');
            const searchValue = searchInput.value.toLowerCase();
            populateEventEvaluationSelectionModal(searchValue);
        }

        function populateEventEvaluationSelectionModal(searchFilter = '') {
            const eventList = document.getElementById('eventEvaluationSelectionList');
            
            if (!evaluationEvents || evaluationEvents.length === 0) {
                eventList.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No events available for evaluation analytics.</p>';
                return;
            }

            // Filter events based on search
            const filteredEvents = evaluationEvents.filter(event => {
                if (!searchFilter) return true;
                
                return event.title.toLowerCase().includes(searchFilter) ||
                       event.category.toLowerCase().includes(searchFilter) ||
                       event.location.toLowerCase().includes(searchFilter) ||
                       event.formatted_date.toLowerCase().includes(searchFilter);
            });

            if (filteredEvents.length === 0) {
                eventList.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No events match your search criteria.</p>';
                return;
            }

            // Generate event list HTML
            eventList.innerHTML = filteredEvents.map(event => `
                <div class="event-item" style="padding: 1rem; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: all 0.2s ease;" 
                     onmouseover="this.style.backgroundColor='#f8f9fa'; this.style.borderColor='#007bff';" 
                     onmouseout="this.style.backgroundColor='white'; this.style.borderColor='#e0e0e0';"
                     onclick="selectEvaluationEvent(${event.id}, '${event.title.replace(/'/g, '\\\'')}')"
                >
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 1.1rem; font-weight: 600;">${event.title}</h4>
                            <p style="margin: 0 0 0.25rem 0; color: #666; font-size: 0.9rem;">📅 ${event.formatted_date || event.date || 'Date not available'}</p>
                            <p style="margin: 0 0 0.25rem 0; color: #666; font-size: 0.9rem;">📍 ${event.location || 'Location not specified'}</p>
                            <p style="margin: 0; color: #666; font-size: 0.9rem;">🏷️ ${event.category || 'Category not specified'}</p>
                        </div>
                        <div style="text-align: right;">
                            <span style="background: #e3f2fd; color: #1976d2; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.8rem; font-weight: 500;">
                                ${event.evaluation_count || event.evaluations_count || 0} evaluation(s)
                            </span>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        function selectEvaluationEvent(eventId, eventTitle) {
            // Store selected event
            window.selectedEvaluationEventId = eventId;
            window.selectedEvaluationEventTitle = eventTitle;
            
            // Set mode to individual
            currentEvaluationMode = 'individual';
            
            // Update selected event display
            const selectedEventDisplay = document.getElementById('selectedEvaluationEventDisplay');
            if (selectedEventDisplay) {
                selectedEventDisplay.textContent = eventTitle;
                selectedEventDisplay.style.display = 'inline';
            }
            
            console.log('Selected event for evaluation:', eventTitle, 'Event ID:', eventId);
            
            // Close modal
            closeEventEvaluationSelectionModal();
            
            // Reload analytics with selected event
            loadEvaluationAnalytics();
        }

        // Initialize evaluation analytics when the reports section becomes active
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we're already on the reports section (via URL parameter)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('section') === 'reports-analytics') {
                setTimeout(() => {
                    initializeEvaluationAnalytics();
                }, 100);
            }

            // Add click-outside-to-close functionality for all modals
            const modals = [
                'applicationsModal',
                'studentProfileModal', 
                'allEventsModal',
                'borrowRequestsModal',
                'returnsModal',
                'addItemModal',
                'itemSelectionModal',
                'eventParticipantsModal'
            ];

            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            // Close the modal based on its type
                            switch(modalId) {
                                case 'applicationsModal':
                                    closeApplicationsModal();
                                    break;
                                case 'studentProfileModal':
                                    closeStudentModal();
                                    break;
                                case 'allEventsModal':
                                    closeAllEventsModal();
                                    break;
                                case 'borrowRequestsModal':
                                    closeBorrowRequestsModal();
                                    break;
                                case 'returnsModal':
                                    closeReturnsModal();
                                    break;
                                case 'addItemModal':
                                    closeAddItemModal();
                                    break;
                                case 'itemSelectionModal':
                                    closeItemSelectionModal();
                                    break;
                                case 'eventParticipantsModal':
                                    closeParticipantsModal();
                                    break;
                            }
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
