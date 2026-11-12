<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();

// Get student information
$student_id = $_SESSION['user_id'];
$student_info = null;
$user_email = null;

try {
    // Check which table the user comes from
    $user_table = $_SESSION['user_table'] ?? 'users';
    
    if ($user_table === 'student_artists') {
        // User is from student_artists table
        $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE id = ?");
        $stmt->execute([$student_id]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_email = $student_info['email'] ?? null;
    } else {
        // User is from users table, try to find corresponding record in student_artists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_info) {
            $user_email = $user_info['email'];
            // Try to find matching student record by email
            $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE email = ?");
            $stmt->execute([$user_info['email']]);
            $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no matching record found, create a basic student info from users table
            if (!$student_info) {
                $student_info = [
                    'id' => $user_info['id'],
                    'first_name' => $user_info['first_name'],
                    'middle_name' => $user_info['middle_name'],
                    'last_name' => $user_info['last_name'],
                    'email' => $user_info['email'],
                    'sr_code' => null,
                    'campus' => null,
                    'college' => null,
                    'program' => null,
                    'year_level' => null,
                    'contact_number' => null,
                    'address' => null,
                    'status' => $user_info['status']
                ];
            }
        }
    }
    
    if (!$student_info) {
        // Try to get from applications table if not in student_artists
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ? AND status = 'approved'");
        $stmt->execute([$student_id]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Fetch profile photo from applications table if available
    $profile_photo = null;
    if ($user_email) {
        $stmt = $pdo->prepare("SELECT profile_photo FROM applications WHERE email = ? AND (application_status = 'approved' OR status = 'approved') LIMIT 1");
        $stmt->execute([$user_email]);
        $photo_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile_photo = $photo_result['profile_photo'] ?? null;
    } elseif ($student_info && isset($student_info['email'])) {
        $stmt = $pdo->prepare("SELECT profile_photo FROM applications WHERE email = ? AND (application_status = 'approved' OR status = 'approved') LIMIT 1");
        $stmt->execute([$student_info['email']]);
        $photo_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile_photo = $photo_result['profile_photo'] ?? null;
    }
} catch (Exception $e) {
    error_log("Error fetching student info: " . $e->getMessage());
}

// Get dashboard statistics for student
try {
    // Get the actual student ID for queries (handle both user tables)
    $actual_student_id = $student_id;
    $user_email = null;
    
    // Get user email for cross-table lookups
    if ($user_table === 'student_artists') {
        $stmt = $pdo->prepare("SELECT email FROM student_artists WHERE id = ?");
        $stmt->execute([$student_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_email = $user_data['email'] ?? null;
    } else {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_email = $user_data['email'] ?? null;
    }
    
    // Count all visible, non-ended events from today onward
    $studentCulturalGroup = $student_info['cultural_group'] ?? null;
    $studentCampus = $student_info['campus'] ?? null;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM events 
        WHERE status IN ('published', 'ongoing')
        AND end_date >= CURRENT_DATE
        AND (cultural_groups LIKE ? OR cultural_groups LIKE ? OR cultural_groups = '[]' OR cultural_groups IS NULL)
        AND (campus = ? OR campus IS NULL OR campus = '')
    ");
    $groupPattern1 = '%"' . ($studentCulturalGroup ?? '') . '"%';
    $groupPattern2 = '%' . ($studentCulturalGroup ?? '') . '%';
    $stmt->execute([$groupPattern1, $groupPattern2, $studentCampus]);
    $upcoming_events = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Count currently borrowed costumes for this student
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM borrowing_requests 
        WHERE student_id = ? 
        AND status IN ('approved') 
        AND current_status IN ('active', 'pending_return')
    ");
    $stmt->execute([$student_id]);
    $borrowed_costumes = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Count total performances/events this student has participated in
    // Try both student_id and email-based lookup
    $performance_count = 0;
    
    // First try with current student_id
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM event_participants ep
        JOIN events e ON ep.event_id = e.id
        WHERE ep.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $performance_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // If no results and we have email, try to find by email match
    if ($performance_count == 0 && $user_email) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM event_participants ep
            JOIN events e ON ep.event_id = e.id
            JOIN student_artists sa ON ep.student_id = sa.id
            WHERE sa.email = ?
        ");
        $stmt->execute([$user_email]);
        $performance_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    }
    
    $total_performances = $performance_count;
    
    // Count announcements (including events as announcements - matching get_announcements.php logic)
    // Get student's cultural group and campus for filtering
    $studentStmt = $pdo->prepare("SELECT cultural_group, campus FROM student_artists WHERE id = ?");
    $studentStmt->execute([$_SESSION['user_id']]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    $total_announcements = 0;
    
    if ($student) {
        $studentCulturalGroup = $student['cultural_group'];
        $studentCampus = $student['campus'];
        
        // Count regular announcements (active, published, not expired)
        $announcementsStmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM announcements a
            WHERE (
                (a.target_cultural_group LIKE ? OR a.target_cultural_group LIKE ? OR a.target_cultural_group = 'all')
                OR (a.target_audience = 'all' OR a.target_audience = 'students')
            )
            AND (a.target_campus = 'all' OR a.target_campus = ? OR a.target_campus IS NULL)
            AND a.is_active = 1
            AND a.is_published = 1
            AND (a.expiry_date IS NULL OR a.expiry_date >= CURRENT_DATE)
        ");
        
        $groupPattern1 = '%"' . $studentCulturalGroup . '"%';
        $groupPattern2 = '%' . $studentCulturalGroup . '%';
        
        $announcementsStmt->execute([$groupPattern1, $groupPattern2, $studentCampus]);
        $announcementCount = $announcementsStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        // Count event announcements (upcoming and ongoing events)
        $eventsStmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM events
            WHERE (cultural_groups LIKE ? OR cultural_groups LIKE ? OR cultural_groups = '[]')
            AND (campus = ? OR campus IS NULL OR campus = '')
            AND end_date >= CURRENT_DATE
            AND status IN ('published', 'ongoing')
        ");
        
        $eventsStmt->execute([$groupPattern1, $groupPattern2, $studentCampus]);
        $eventCount = $eventsStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        $total_announcements = $announcementCount + $eventCount;
    }
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $upcoming_events = 0;
    $borrowed_costumes = 0;
    $total_performances = 0;
    $total_announcements = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/jpeg" href="../assets/logo.jpg?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Culture and Arts</title>
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
            line-height: 1.6;
        }

        /* Prevent background scrolling when modals are open */
        body.modal-open {
            overflow: hidden;
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            color: #333;
            width: auto;
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
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 70px;
            left: 0;
            height: calc(100vh - 70px);
            overflow-y: auto;
            z-index: 90;
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
            margin: 0;
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
            padding: 2rem;
            overflow-y: auto;
            margin-left: 280px;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            color: #333;
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: #666;
            font-size: 1rem;
        }

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-icon {
            font-size: 1.5rem;
            color: #dc2626;
        }

        .card-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            color: #888;
            font-size: 0.9rem;
        }

        /* Content Panels */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .content-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .panel-header {
            background: #dc2626;
            color: white;
            padding: 1rem 1.5rem;
            margin: -1px -1px 0 -1px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .panel-content {
            padding: 1.5rem;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            text-align: center;
        }

        /* Upcoming Events Styles */
        .events-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .events-header {
            background: #28a745;
            color: white;
            padding: 1rem 1.5rem;
        }

        .events-list {
            padding: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }

        .event-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .event-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .event-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .event-date {
            color: #dc2626;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .event-location {
            color: #666;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #888;
        }

        /* Profile Styles */
        .profile-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .profile-header {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            overflow: hidden;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .profile-role {
            opacity: 0.9;
        }

        .profile-details {
            padding: 1.5rem;
        }

        .detail-group {
            margin-bottom: 1.5rem;
        }

        .detail-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .detail-value {
            color: #666;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 4px;
        }

        /* Performer Profile Form Styles */
        .performer-profile-container {
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

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
            margin-bottom: 0.5rem;
            overflow: hidden;
        }

        .performer-profile-card .photo-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .performer-profile-card .photo-edit-controls {
            display: none;
            gap: 0.5rem;
            flex-direction: column;
        }

        .performer-profile-card .photo-edit-controls.active {
            display: flex;
        }

        .performer-profile-card .photo-upload-btn,
        .performer-profile-card .photo-delete-btn {
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: background 0.3s ease;
            width: 120px;
        }

        .performer-profile-card .photo-upload-btn {
            background: #ff5a5a;
            color: white;
        }

        .performer-profile-card .photo-upload-btn:hover {
            background: #ff3333;
        }

        .performer-profile-card .photo-delete-btn {
            background: #dc3545;
            color: white;
        }

        .performer-profile-card .photo-delete-btn:hover {
            background: #c82333;
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
        .performer-profile-card .affiliation-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .performer-profile-card .participation-table th,
        .performer-profile-card .affiliation-table th,
        .performer-profile-card .participation-table td,
        .performer-profile-card .affiliation-table td {
            border: 1px solid #333;
            padding: 0.75rem 0.5rem;
            text-align: left;
        }

        .performer-profile-card .participation-table th,
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

        .performer-profile-card .table-edit-controls {
            display: none;
            margin-top: 0.5rem;
        }

        .performer-profile-card .table-edit-controls.active {
            display: block;
        }

        .performer-profile-card .edit-column {
            display: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .dashboard-cards {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                min-height: auto;
            }
            
            .nav-menu {
                display: flex;
                overflow-x: auto;
                padding: 0.5rem 1rem;
            }
            
            .nav-item {
                margin: 0 0.25rem;
                white-space: nowrap;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
            }
            
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Mobile Responsive - 400x651 */
        @media (max-width: 480px) {
            * {
                box-sizing: border-box;
            }

            body {
                overflow-x: hidden;
            }

            /* Header Adjustments */
            .header {
                padding: 0.75rem;
                flex-direction: column;
                gap: 0.5rem;
                align-items: stretch;
            }

            .header-left {
                gap: 0.5rem;
                justify-content: flex-start;
            }

            .logo {
                width: 32px;
                height: 32px;
            }

            .header-title {
                font-size: 0.95rem;
                line-height: 1.2;
            }

            .header-right {
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
            }

            .user-info {
                padding: 0.4rem 0.75rem;
                font-size: 0.8rem;
                flex: 0 1 auto;
                margin-right: 0.5rem;
                gap: 0.25rem;
                max-width: calc(100% - 100px);
            }

            .user-info span {
                font-size: 0.8rem;
            }

            .user-info span[style*="background"] {
                padding: 2px 8px !important;
                font-size: 0.7rem !important;
                margin-left: 5px !important;
            }

            .logout-btn {
                padding: 0.4rem 0.75rem;
                font-size: 0.8rem;
                white-space: nowrap;
            }

            /* Sidebar Mobile */
            .sidebar {
                position: relative;
                top: 0;
                left: 0;
                width: 100%;
                min-height: auto;
                height: auto;
                box-shadow: none;
                border-bottom: 1px solid #e0e0e0;
                margin-bottom: 0;
            }

            .nav-menu {
                display: flex;
                overflow-x: auto;
                padding: 0.5rem;
                margin: 0;
                gap: 0.25rem;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .nav-menu::-webkit-scrollbar {
                display: none;
            }

            .nav-item {
                flex-shrink: 0;
                margin: 0;
            }

            .nav-link {
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
                white-space: nowrap;
                border-left: none;
                border-bottom: 3px solid transparent;
                border-radius: 6px;
            }

            .nav-link.active {
                border-left: none;
                border-bottom-color: #dc2626;
            }

            /* Main Container Mobile */
            .main-container {
                flex-direction: column;
                min-height: auto;
                margin-top: 0;
            }

            /* Main Content */
            .main-content {
                padding: 0.75rem;
                width: 100%;
                margin-left: 0;
                margin-top: 0;
                max-width: 100%;
                overflow-x: hidden;
            }

            /* Content sections */
            .content-section {
                width: 100%;
                overflow-x: hidden;
            }

            /* Page Header */
            .page-header {
                flex-direction: column;
                gap: 0.75rem;
                align-items: stretch;
                margin-bottom: 1rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            /* Dashboard Cards */
            .dashboard-cards {
                grid-template-columns: 1fr;
                gap: 0.75rem;
                margin-bottom: 1rem;
            }

            .dashboard-card {
                padding: 1rem;
            }

            .card-number {
                font-size: 2rem;
            }

            .card-title {
                font-size: 0.85rem;
            }

            .card-subtitle {
                font-size: 0.8rem;
            }

            /* Content Grid */
            .content-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            /* Content Panels */
            .content-panel {
                margin-bottom: 0.75rem;
                overflow: hidden;
                border-radius: 12px;
            }

            .panel-header {
                padding: 1rem;
                border-radius: 12px 12px 0 0;
            }

            .panel-title {
                font-size: 1rem;
            }

            .panel-content {
                padding: 0;
                font-size: 0.9rem;
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
                max-width: 100%;
            }

            /* Table containers in panels */
            .panel-content .borrowed-costumes-table {
                margin: 0;
                border-radius: 0;
            }

            /* Wrapper for table scrolling */
            .table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }

            /* Profile Grid */
            .profile-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .profile-section {
                padding: 1rem;
            }

            .profile-section h3 {
                font-size: 1rem;
                margin-bottom: 0.75rem;
            }

            .profile-section p {
                font-size: 0.85rem;
                margin: 0.4rem 0;
            }

            .profile-photo-container {
                padding: 1rem;
            }

            .profile-photo {
                width: 120px;
                height: 120px;
            }

            /* Tables */
            .borrowed-costumes-table,
            table {
                font-size: 0.75rem;
                width: 100%;
                min-width: 600px;
                border-collapse: collapse;
                display: table;
            }

            .borrowed-costumes-table thead,
            table thead {
                display: table-header-group;
            }

            .borrowed-costumes-table tbody,
            table tbody {
                display: table-row-group;
            }

            .borrowed-costumes-table tr,
            table tr {
                display: table-row;
            }

            .borrowed-costumes-table th,
            .borrowed-costumes-table td,
            table th,
            table td {
                display: table-cell;
                padding: 0.75rem 0.5rem;
                font-size: 0.7rem;
                line-height: 1.3;
                vertical-align: middle;
                white-space: nowrap;
            }

            .borrowed-costumes-table th:first-child,
            .borrowed-costumes-table td:first-child {
                padding-left: 0.75rem;
            }

            .borrowed-costumes-table th:last-child,
            .borrowed-costumes-table td:last-child {
                padding-right: 0.75rem;
            }

            .item-name-cell {
                font-weight: 600;
                font-size: 0.7rem;
            }

            .date-cell {
                font-size: 0.7rem;
            }

            .item-status {
                padding: 0.25rem 0.5rem;
                font-size: 0.65rem;
                display: inline-block;
                white-space: nowrap;
            }

            .return-btn,
            .action-btn {
                padding: 0.5rem 0.65rem;
                font-size: 0.7rem;
                white-space: nowrap;
            }

            /* Action Buttons */
            .action-btn {
                width: 100%;
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }

            .add-btn {
                width: 100%;
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
                justify-content: center;
            }

            /* Modals */
            .modal {
                padding: 0;
                align-items: flex-start;
            }

            .modal-content {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                border-radius: 0 !important;
                max-height: 100vh !important;
                min-height: 100vh;
                overflow-y: auto;
            }

            .modal-header {
                padding: 1rem !important;
                position: sticky;
                top: 0;
                background: white;
                z-index: 100;
                border-bottom: 1px solid #e0e0e0;
            }

            .modal-header h2 {
                font-size: 1.1rem !important;
            }

            .close {
                font-size: 1.8rem !important;
                width: 36px;
                height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .modal-body {
                padding: 1rem !important;
            }

            /* Forms */
            .form-group {
                margin-bottom: 1rem;
            }

            .form-group label {
                font-size: 0.9rem;
                margin-bottom: 0.4rem;
            }

            .form-group input,
            .form-group textarea,
            .form-group select {
                width: 100%;
                padding: 0.6rem;
                font-size: 0.9rem;
            }

            .form-group textarea {
                min-height: 80px;
            }

            /* Search Inputs */
            .search-input,
            input[type="text"],
            input[type="search"] {
                width: 100% !important;
                padding: 0.6rem !important;
                font-size: 0.9rem !important;
            }

            /* Event Cards */
            .event-card {
                padding: 1rem;
                margin-bottom: 0.75rem;
            }

            .event-title {
                font-size: 1rem;
            }

            .event-date,
            .event-location,
            .event-description {
                font-size: 0.85rem;
            }

            /* Empty States */
            .empty-state,
            .empty-borrowed-state {
                padding: 2rem 1rem;
                min-height: 150px;
            }

            .empty-state p,
            .empty-borrowed-state p {
                font-size: 1rem;
            }

            .empty-state small,
            .empty-borrowed-state small {
                font-size: 0.85rem;
            }

            /* Evaluation Modal */
            .evaluation-question {
                padding: 0.75rem 0.5rem !important;
                flex-direction: column !important;
                align-items: flex-start !important;
            }

            .evaluation-question > div:first-child {
                margin-bottom: 0.75rem;
                font-size: 0.9rem;
            }

            .evaluation-question > div:not(:first-child) {
                display: block !important;
                width: 100%;
                margin: 0.4rem 0 !important;
                padding: 0.5rem;
                background: #f8f9fa;
                border-radius: 4px;
            }

            .evaluation-question label {
                display: flex;
                align-items: center;
                cursor: pointer;
                font-size: 0.85rem;
            }

            .evaluation-question input[type="radio"] {
                margin-right: 0.5rem;
                transform: scale(1.3);
            }

            /* Rating Stars */
            .rating-container label {
                font-size: 1.5rem !important;
                padding: 0.25rem !important;
            }

            /* Borrowed Item Details */
            .notes-cell {
                max-width: 150px;
                font-size: 0.75rem;
            }

            .date-cell {
                font-size: 0.75rem;
            }

            /* Loading States */
            .loading-state {
                padding: 1.5rem 1rem;
                font-size: 0.9rem;
            }

            /* Scrollbar Improvements */
            .panel-content::-webkit-scrollbar,
            .modal-body::-webkit-scrollbar {
                width: 6px;
                height: 6px;
            }

            .panel-content::-webkit-scrollbar-thumb,
            .modal-body::-webkit-scrollbar-thumb {
                background: #ccc;
                border-radius: 3px;
            }

            /* Touch Target Improvements */
            button,
            .nav-link,
            .action-btn,
            .btn,
            a {
                min-height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            /* Prevent Horizontal Overflow */
            .content-section,
            .table-container,
            .panel-content {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Status Badges */
            .status-badge {
                font-size: 0.7rem;
                padding: 0.2rem 0.5rem;
            }

            /* Image Responsive */
            img {
                max-width: 100%;
                height: auto;
            }

            /* Announcement Cards */
            .announcement-card {
                padding: 1rem;
                margin-bottom: 0.75rem;
            }

            .announcement-card h3 {
                font-size: 1rem;
            }

            .announcement-card p {
                font-size: 0.85rem;
            }

            /* Fix Select Dropdowns */
            select {
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 0.75rem center;
                padding-right: 2.5rem;
            }
        }

        /* Action Buttons */
        .action-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .action-btn:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }

        .action-btn.secondary {
            background: #6c757d;
        }

        .action-btn.secondary:hover {
            background: #5a6268;
        }

        .action-btn.success {
            background: #28a745;
        }

        .action-btn.success:hover {
            background: #218838;
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
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 2rem;
            border: none;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            animation: slideDown 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideDown {
            from { 
                transform: translateY(-50px);
                opacity: 0;
            }
            to { 
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e0e0e0;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
            font-size: 1.5rem;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close:hover,
        .close:focus {
            color: #dc2626;
        }

        .modal-body {
            margin-bottom: 1.5rem;
        }

        .modal-body p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .modal-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .modal-input:focus {
            outline: none;
            border-color: #dc2626;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .modal-footer button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .editable-field {
            padding: 0.5rem;
            border: 2px solid transparent;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .editable-field:focus {
            outline: none;
            border-color: #dc2626;
            background: #fff;
        }

        .editable-field[contenteditable="false"] {
            background: #f8f9fa;
        }

        /* Table Edit Buttons */
        .table-edit-controls {
            display: none;
            margin-top: 1rem;
            gap: 0.5rem;
        }

        .table-edit-controls.active {
            display: flex;
        }

        .edit-table-row {
            background: #f8f9fa;
        }

        .edit-table-row input,
        .edit-table-row select {
            width: 100%;
            padding: 0.5rem;
            border: 2px solid #dc2626;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .edit-table-row select {
            background: white;
            cursor: pointer;
        }

        .delete-row-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .delete-row-btn:hover {
            background: #c82333;
        }

        .photo-edit-controls {
            display: none;
            margin-top: 1rem;
            gap: 0.5rem;
            justify-content: center;
        }

        .photo-edit-controls.active {
            display: flex;
        }

        .photo-upload-btn, .photo-delete-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .photo-upload-btn {
            background: #28a745;
            color: white;
        }

        .photo-upload-btn:hover {
            background: #218838;
        }

        .photo-delete-btn {
            background: #dc3545;
            color: white;
        }

        .photo-delete-btn:hover {
            background: #c82333;
        }

        /* Borrowed Costumes Styles */
        .borrowed-costumes-list {
            padding: 0;
            margin: 0;
        }

        #borrowedCostumesContent.panel-content {
            padding: 0;
            min-height: 0;
            margin: 0;
            display: block;
            align-items: unset;
            justify-content: unset;
            text-align: left;
        }

        /* Events Participated panel content styling for seamless table connection */
        #performance-record .content-panel .panel-content {
            padding: 0;
            min-height: 0;
            margin: 0;
            display: block;
            align-items: unset;
            justify-content: unset;
            text-align: left;
        }

        /* Ensure borrowed costumes panel header extends fully */
        #costume-borrowing .content-panel .panel-header {
            margin: -1px -1px 0 -1px;
        }

        .borrowed-costumes-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 0;
            overflow: hidden;
            margin: 0;
            margin-top: -1px; /* Pull table up to merge with header */
            margin-left: 0;
            margin-right: 0;
            border-left: none;
            border-right: none;
        }

        .borrowed-costumes-table thead {
            background: #dc2626;
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .borrowed-costumes-table th:first-child,
        .borrowed-costumes-table td:first-child {
            padding-left: 1.5rem;
        }

        .borrowed-costumes-table th:last-child,
        .borrowed-costumes-table td:last-child {
            padding-right: 1.5rem;
        }

        .borrowed-costumes-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .borrowed-costumes-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
        }

        .borrowed-costumes-table tbody tr:hover {
            background: #f8f9fa;
        }

        .borrowed-costumes-table tbody tr:last-child td {
            border-bottom: none;
        }

        .item-name-cell {
            font-weight: 600;
            color: #333;
        }

        .item-category-cell {
            color: #666;
            font-size: 0.9rem;
            text-transform: capitalize;
        }

        .item-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .item-description {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.4;
        }

        .item-status {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-returned {
            background: #e5e7eb;
            color: #374151;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .date-cell {
            font-size: 0.9rem;
            color: #555;
        }

        .notes-cell {
            font-size: 0.85rem;
            color: #666;
            font-style: italic;
            max-width: 200px;
            word-wrap: break-word;
        }

        .loading-state {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .action-cell {
            text-align: left;
            padding: 0.5rem;
        }

        .return-btn {
            background: #28a745;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }

        .return-btn:hover {
            background: #218838;
        }

        .empty-borrowed-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #888;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .empty-borrowed-state p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .empty-borrowed-state small {
            color: #aaa;
        }

        /* Evaluation Modal Responsive Styles */
        .evaluation-question {
            transition: background-color 0.2s ease;
        }

        .evaluation-question:hover {
            background-color: #f8f9fa;
        }

        .evaluation-question input[type="radio"] {
            transform: scale(1.2);
            cursor: pointer;
        }

        @media (max-width: 768px) {
            #eventEvaluationModal .evaluation-question {
                display: block !important;
                padding: 1rem 0.5rem;
            }
            
            #eventEvaluationModal .evaluation-question > div:first-child {
                margin-bottom: 1rem;
                font-weight: 600;
            }
            
            #eventEvaluationModal .evaluation-question > div:not(:first-child) {
                display: inline-block;
                margin: 0.25rem 0.5rem;
                text-align: left !important;
            }
            
            #eventEvaluationModal .evaluation-question input[type="radio"] {
                margin-right: 0.5rem;
            }
            
            #eventEvaluationModal > div {
                margin: 1rem;
                width: calc(100% - 2rem);
            }
            
            #eventEvaluationModal form {
                padding: 1rem;
            }
        }

        /* Performer Profile Form View Styles - All scoped to .performer-profile-card */
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/logo.jpg" alt="BatStateU Logo" class="logo">
            <h1 class="header-title">Student Dashboard - Culture and Arts</h1>
        </div>
        <div class="header-right">
            <?php 
            $first_name = $student_info['first_name'] ?? 'Student';
            $campus = $student_info['campus'] ?? '';
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
                <p>STUDENT - <?= htmlspecialchars($campus) ?></p>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="#" class="nav-link active" data-section="dashboard">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="announcements">
                            Announcements
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="upcoming-events">
                            Upcoming Events
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="performance-record">
                            Performance Record
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="costume-borrowing">
                            Costume Borrowing
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="profile">
                            Profile
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Dashboard Section -->
            <section class="content-section active" id="dashboard">
                <div class="page-header">
                    <h1 class="page-title">Dashboard Overview</h1>
                    <p class="page-subtitle">Welcome to your Culture and Arts dashboard</p>
                </div>

                <!-- Dashboard Cards -->
                <div class="dashboard-cards">
                    <div class="dashboard-card stat-card">
                        <div class="card-header">
                            <div class="card-title">Announcements</div>
                            <div class="card-icon">📢</div>
                        </div>
                        <div class="card-number stat-number"><?= $total_announcements ?></div>
                        <div class="card-subtitle">New announcements</div>
                    </div>

                    <div class="dashboard-card stat-card">
                        <div class="card-header">
                            <div class="card-title">Upcoming Events</div>
                            <div class="card-icon">📅</div>
                        </div>
                        <div class="card-number stat-number"><?= $upcoming_events ?></div>
                        <div class="card-subtitle">Events this month</div>
                    </div>

                    <div class="dashboard-card stat-card">
                        <div class="card-header">
                            <div class="card-title">Performances</div>
                            <div class="card-icon">🎭</div>
                        </div>
                        <div class="card-number stat-number"><?= $total_performances ?></div>
                        <div class="card-subtitle">Total performances</div>
                    </div>

                    <div class="dashboard-card stat-card">
                        <div class="card-header">
                            <div class="card-title">Borrowed Costumes</div>
                            <div class="card-icon">👗</div>
                        </div>
                        <div class="card-number stat-number"><?= $borrowed_costumes ?></div>
                        <div class="card-subtitle">Currently borrowed</div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Recent Announcements</h3>
                        </div>
                        <div class="panel-content">
                            <div class="empty-state">
                                <p>No recent announcements</p>
                                <small>Check back later for updates</small>
                            </div>
                        </div>
                    </div>

                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Next Performance</h3>
                        </div>
                        <div class="panel-content">
                            <div class="empty-state">
                                <p>No upcoming performances</p>
                                <small>Your next performance will appear here</small>
                            </div>
                        </div>
                    </div>

                    <div class="content-panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Costume Returns</h3>
                        </div>
                        <div class="panel-content">
                            <div class="empty-state">
                                <p>No costume returns due</p>
                                <small>Your borrowed costumes will appear here</small>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Announcements Section -->
            <section class="content-section" id="announcements">
                <div class="page-header">
                    <h1 class="page-title">Announcements</h1>
                    <p class="page-subtitle">Stay updated with the latest news and announcements</p>
                </div>

                <div class="content-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">All Announcements</h3>
                    </div>
                    <div class="panel-content">
                        <div class="empty-state">
                            <p>No announcements available</p>
                            <small>Announcements will appear here when posted</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Upcoming Events Section -->
            <section class="content-section" id="upcoming-events">
                <div class="page-header">
                    <h1 class="page-title">Upcoming Events</h1>
                    <p class="page-subtitle">View and manage your event participations</p>
                </div>

                <div class="events-container">
                    <div class="events-header">
                        <h3 class="panel-title">Events & Trainings</h3>
                    </div>
                    <div class="events-list" id="eventsList">
                        <div class="empty-state">
                            <p>No upcoming events</p>
                            <small>Events you're participating in will appear here</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Performance Record Section -->
            <section class="content-section" id="performance-record">
                <div class="page-header">
                    <h1 class="page-title">Performance Record</h1>
                    <p class="page-subtitle">Track your performance history, achievements, and upload certificates</p>
                </div>

                <!-- Certificate Upload Section -->
                <div class="content-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Certificates & Achievements</h3>
                        <button class="action-btn" onclick="openCertificateUploadModal()">
                            <span>+</span>
                            Upload Certificate
                        </button>
                    </div>
                    <div class="panel-content">
                        <div id="certificatesLoading" class="loading-state" style="display: none;">
                            <p>Loading certificates...</p>
                        </div>
                        <div id="certificatesContainer">
                            <!-- Certificates will be loaded here -->
                        </div>
                    </div>
                </div>

                <!-- Events Participated Section -->
                <div class="content-panel" style="margin-top: 2rem;">
                    <div class="panel-header">
                        <h3 class="panel-title">Events Participated</h3>
                    </div>
                    <div class="panel-content">
                        <div id="eventsLoading" class="loading-state" style="display: none;">
                            <p>Loading your event history...</p>
                        </div>
                        <div id="eventsContainer">
                            <!-- Events will be loaded here -->
                        </div>
                    </div>
                </div>

                <!-- Certificate Upload Modal -->
                <div id="certificateUploadModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
                    <div style="background: white; border-radius: 8px; max-width: 500px; width: 90%; max-height: 80vh; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: #333; font-size: 1.2rem;">Upload Certificate</h3>
                            <button onclick="closeCertificateUploadModal()" style="background: none; border: none; font-size: 1.5rem; color: #666; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <form id="certificateUploadForm" style="padding: 1.5rem;">
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Certificate Title *</label>
                                <input type="text" id="certificateTitle" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit;">
                            </div>
                            
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Description</label>
                                <textarea id="certificateDescription" rows="3" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit; resize: vertical;"></textarea>
                            </div>
                            
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Date Received *</label>
                                <input type="date" id="certificateDate" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                            </div>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #333;">Certificate File *</label>
                                <input type="file" id="certificateFile" accept=".jpg,.jpeg,.png,.pdf" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <small style="color: #666; margin-top: 0.25rem; display: block;">Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="closeCertificateUploadModal()" style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #333; border-radius: 4px; cursor: pointer;">Cancel</button>
                                <button type="submit" style="padding: 0.75rem 1.5rem; border: none; background: #dc2626; color: white; border-radius: 4px; cursor: pointer;">Upload Certificate</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Event Evaluation Modal -->
                <div id="eventEvaluationModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto; padding: 2rem 0;">
                    <div style="background: white; border-radius: 8px; max-width: 800px; width: 90%; margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <div style="padding: 1.5rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background: white;">
                            <h3 style="margin: 0; color: #333; font-size: 1.3rem;">Event Evaluation</h3>
                            <button onclick="closeEventEvaluationModal()" style="background: none; border: none; font-size: 1.5rem; color: #666; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                        </div>
                        <form id="eventEvaluationForm" style="padding: 2rem;">
                            <div style="margin-bottom: 2rem; text-align: center; padding: 1rem; background: #f8f9fa; border-radius: 6px;">
                                <h4 id="evaluationEventTitle" style="margin: 0; color: #333; font-size: 1.1rem;">Event Title</h4>
                                <p style="margin: 0.5rem 0 0 0; color: #666; font-size: 0.9rem;">Please rate the following aspects of the event on a scale of 1-5</p>
                            </div>

                            <!-- Likert Scale Questions -->
                            <div style="margin-bottom: 2rem;">
                                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #dc2626; color: white; font-weight: 600; border-radius: 4px;">
                                    <div>Evaluation Criteria</div>
                                    <div style="text-align: center;">Strongly Disagree (1)</div>
                                    <div style="text-align: center;">Disagree (2)</div>
                                    <div style="text-align: center;">Neutral (3)</div>
                                    <div style="text-align: center;">Agree (4)</div>
                                    <div style="text-align: center;">Strongly Agree (5)</div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">1. Overall, how would you rate the seminar/training?</div>
                                    <div style="text-align: center;"><input type="radio" name="q1" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q1" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q1" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q1" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q1" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">2. How would you rate the appropriateness of time and the proper use of resources provided?</div>
                                    <div style="text-align: center;"><input type="radio" name="q2" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q2" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q2" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q2" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q2" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">3. Objectives and expectations were clearly communicated and achieved.</div>
                                    <div style="text-align: center;"><input type="radio" name="q3" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q3" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q3" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q3" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q3" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">4. Session activities were appropriate and relevant to the achievement of the learning objectives.</div>
                                    <div style="text-align: center;"><input type="radio" name="q4" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q4" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q4" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q4" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q4" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">5. Sufficient time was allotted for group discussion and comments.</div>
                                    <div style="text-align: center;"><input type="radio" name="q5" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q5" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q5" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q5" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q5" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">6. Materials and task were useful.</div>
                                    <div style="text-align: center;"><input type="radio" name="q6" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q6" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q6" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q6" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q6" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">7. The resource person/trainer displayed thorough knowledge of, and provided relevant insights on the topic/s discussed.</div>
                                    <div style="text-align: center;"><input type="radio" name="q7" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q7" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q7" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q7" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q7" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">8. The resource person/trainer thoroughly explained and processed the learning activities throughout the training.</div>
                                    <div style="text-align: center;"><input type="radio" name="q8" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q8" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q8" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q8" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q8" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">9. The resource person/trainer created a good learning environment, sustained the attention of the participants, and encouraged their participation in the training duration.</div>
                                    <div style="text-align: center;"><input type="radio" name="q9" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q9" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q9" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q9" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q9" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">10. The resource person/trainer used the time well, including some adjustments in the training schedule, if needed.</div>
                                    <div style="text-align: center;"><input type="radio" name="q10" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q10" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q10" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q10" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q10" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 0.5rem;">
                                    <div style="font-weight: 500;">11. The resource person/trainer demonstrated keenness to the participants' needs and other requirements related to the training.</div>
                                    <div style="text-align: center;"><input type="radio" name="q11" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q11" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q11" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q11" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q11" value="5" required></div>
                                </div>

                                <div class="evaluation-question" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center; padding: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 1rem;">
                                    <div style="font-weight: 500;">12. The venue or platform used was conducive for learning.</div>
                                    <div style="text-align: center;"><input type="radio" name="q12" value="1" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q12" value="2" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q12" value="3" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q12" value="4" required></div>
                                    <div style="text-align: center;"><input type="radio" name="q12" value="5" required></div>
                                </div>
                            </div>

                            <!-- Open-Ended Questions Section -->
                            <div style="margin-bottom: 2rem;">
                                <div style="margin-bottom: 1.5rem;">
                                    <label for="q13_opinion" style="display: block; margin-bottom: 0.75rem; font-weight: 600; color: #333; font-size: 1rem;">13. Was the training helpful for you in the practice of your profession? Why or why not?</label>
                                    <textarea id="q13_opinion" name="q13_opinion" rows="3" required style="width: 100%; padding: 1rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit; resize: vertical;" placeholder="Please share your opinion..."></textarea>
                                </div>

                                <div style="margin-bottom: 1.5rem;">
                                    <label for="q14_suggestions" style="display: block; margin-bottom: 0.75rem; font-weight: 600; color: #333; font-size: 1rem;">14. What aspect of the training has been helpful to you? What other topics would you suggest for future trainings?</label>
                                    <textarea id="q14_suggestions" name="q14_suggestions" rows="3" required style="width: 100%; padding: 1rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit; resize: vertical;" placeholder="Please share helpful aspects and your suggestions..."></textarea>
                                </div>

                                <div style="margin-bottom: 1.5rem;">
                                    <label for="q15_comments" style="display: block; margin-bottom: 0.75rem; font-weight: 600; color: #333; font-size: 1rem;">15. Comments/Recommendations/Complaints:</label>
                                    <textarea id="q15_comments" name="q15_comments" rows="4" required style="width: 100%; padding: 1rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit; resize: vertical;" placeholder="Please share your thoughts, suggestions, or additional feedback..."></textarea>
                                </div>
                            </div>

                            <!-- Hidden field for event ID -->
                            <input type="hidden" id="evaluationEventId" name="event_id" value="">

                            <!-- Submit Buttons -->
                            <div style="display: flex; gap: 1rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #e0e0e0;">
                                <button type="button" onclick="closeEventEvaluationModal()" style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; background: white; color: #333; border-radius: 4px; cursor: pointer; font-weight: 500;">Cancel</button>
                                <button type="submit" style="padding: 0.75rem 2rem; border: none; background: #17a2b8; color: white; border-radius: 4px; cursor: pointer; font-weight: 600;">Submit Evaluation</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Costume Borrowing Section -->
            <section class="content-section" id="costume-borrowing">
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h1 class="page-title">Costume Borrowing</h1>
                            <p class="page-subtitle">Manage your costume borrowing and returns</p>
                        </div>
                        <button class="action-btn" onclick="openCostumeBorrowingForm()">
                            Costume Borrowing Form
                        </button>
                    </div>
                </div>

                <div class="content-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">My Borrowed Costumes</h3>
                    </div>
                    <div class="panel-content" id="borrowedCostumesContent">
                        <div class="loading-state" id="borrowedCostumesLoading">
                            <p>Loading borrowed costumes...</p>
                        </div>
                        <div class="borrowed-costumes-list" id="borrowedCostumesList" style="display: none;">
                            <!-- Borrowed costumes will be loaded here -->
                        </div>
                    </div>
                </div>
            </section>

            <!-- Profile Section -->
            <section class="content-section" id="profile">
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <h1 class="page-title">My Profile</h1>
                            <p class="page-subtitle">View and edit your performer profile information</p>
                        </div>
                        <div style="display: flex; gap: 1rem;" id="profileActions">
                            <button class="action-btn" id="exportProfileBtn" onclick="exportProfileToPDF()" style="display: none; background: linear-gradient(135deg, #10b981, #059669);">
                                📄 Export to PDF
                            </button>
                            <button class="action-btn" id="editProfileBtn" onclick="requestEditProfile()" style="display: inline-block;">
                                ✏️ Edit Profile
                            </button>
                            <button class="action-btn success" id="saveProfileBtn" onclick="saveProfile()" style="display: none;">
                                💾 Save Changes
                            </button>
                            <button class="action-btn secondary" id="cancelEditBtn" onclick="cancelEdit()" style="display: none;">
                                ✖️ Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <div id="profileLoading" class="loading-state" style="display: none; text-align: center; padding: 2rem;">
                    <p>Loading profile data...</p>
                </div>

                <div id="profileError" style="display: none; text-align: center; padding: 3rem; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                    <h3 style="color: #dc2626; margin-bottom: 1rem;">No Performer Profile Found</h3>
                    <p style="color: #666; margin-bottom: 2rem;">You haven't submitted your performer profile form yet.</p>
                    <a href="performer-profile-form.php" style="display: inline-block; background: linear-gradient(135deg, #ff5a5a, #ff7a6b); color: white; padding: 0.75rem 2rem; border-radius: 6px; text-decoration: none; font-weight: 600; box-shadow: 0 4px 15px rgba(255, 90, 90, 0.3);">
                        Submit Performer Profile Form
                    </a>
                </div>

                <div id="performerProfileView" style="display: none;">
                    <!-- This will be populated with the performer profile form -->
                </div>
            </section>
        </main>
    </div>

    <!-- Password Verification Modal -->
    <div id="passwordVerificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🔒 Verify Your Identity</h2>
                <span class="close" onclick="closePasswordModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p><strong>Are you sure you want to edit your profile?</strong></p>
                <p>Please enter your password to confirm your identity and enable editing.</p>
                <input type="password" id="verifyPassword" class="modal-input" placeholder="Enter your password" />
                <div id="passwordError" style="color: #dc2626; margin-top: 0.5rem; display: none;"></div>
            </div>
            <div class="modal-footer">
                <button class="action-btn secondary" onclick="closePasswordModal()">Cancel</button>
                <button class="action-btn" onclick="verifyPassword()">Verify & Edit</button>
            </div>
        </div>
    </div>

    <!-- Save Confirmation Modal -->
    <div id="saveConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>💾 Save Changes?</h2>
                <span class="close" onclick="closeSaveModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p><strong>Do you want to save your changes?</strong></p>
                <p>Your profile information will be updated with the changes you made.</p>
            </div>
            <div class="modal-footer">
                <button class="action-btn secondary" onclick="closeSaveModal()">No, Cancel</button>
                <button class="action-btn success" onclick="confirmSave()">Yes, Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        // Modal scroll prevention functions
        function preventBackgroundScroll() {
            document.body.classList.add('modal-open');
        }

        function allowBackgroundScroll() {
            document.body.classList.remove('modal-open');
        }

        // Navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link');
            const contentSections = document.querySelectorAll('.content-section');

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
            } else {
                // Default to dashboard if section not found
                document.querySelector('[data-section="dashboard"]').classList.add('active');
                document.getElementById('dashboard').classList.add('active');
            }

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
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
                        
                        // Load data specific to section
                        if (sectionId === 'announcements') {
                            loadAnnouncements();
                        } else if (sectionId === 'upcoming-events') {
                            loadUpcomingEvents();
                        } else if (sectionId === 'costume-borrowing') {
                            loadBorrowedCostumes();
                        } else if (sectionId === 'profile') {
                            loadPerformerProfile();
                        }
                    }

                    // Update URL without page reload
                    const newUrl = `${window.location.pathname}?section=${sectionId}`;
                    window.history.pushState({}, '', newUrl);
                });
            });

            // Load initial data if needed
            if (activeSection === 'announcements') {
                loadAnnouncements();
            } else if (activeSection === 'upcoming-events') {
                loadUpcomingEvents();
            } else if (activeSection === 'costume-borrowing') {
                loadBorrowedCostumes();
            } else if (activeSection === 'profile') {
                loadPerformerProfile();
            }

            // Click outside modal to close functionality
            const modals = ['certificateUploadModal', 'eventEvaluationModal'];

            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            // Call appropriate close function
                            if (modalId === 'certificateUploadModal') {
                                closeCertificateUploadModal();
                            } else if (modalId === 'eventEvaluationModal') {
                                closeEventEvaluationModal();
                            }
                        }
                    });
                }
            });
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../index.php';
            }
        }

        // Load announcements
        function loadAnnouncements() {
            const announcementsContent = document.querySelector('#announcements .panel-content');
            
            // Show loading state
            announcementsContent.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <p>Loading announcements...</p>
                </div>
            `;
            
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAnnouncements(data.announcements);
                    } else {
                        announcementsContent.innerHTML = `
                            <div class="empty-state">
                                <p>Error loading announcements</p>
                                <small>${data.message}</small>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    announcementsContent.innerHTML = `
                        <div class="empty-state">
                            <p>Error loading announcements</p>
                            <small>Please try again later</small>
                        </div>
                    `;
                });
        }

        // Display announcements
        function displayAnnouncements(announcements) {
            const announcementsContent = document.querySelector('#announcements .panel-content');
            
            if (announcements.length === 0) {
                announcementsContent.innerHTML = `
                    <div class="empty-state">
                        <p>No announcements available</p>
                        <small>Announcements will appear here when posted</small>
                    </div>
                `;
                return;
            }

            let html = '<div class="announcements-list">';
            announcements.forEach(announcement => {
                const createdDate = new Date(announcement.created_at).toLocaleDateString();
                const content = announcement.content || announcement.message || 'No content';
                const isPinned = announcement.is_pinned == 1;
                
                html += `
                    <div class="announcement-item" style="border-bottom: 1px solid #e0e0e0; padding: 1rem 0; ${isPinned ? 'background-color: #fff9e6; padding: 1rem; border-radius: 8px; margin-bottom: 0.5rem;' : ''}">
                        ${isPinned ? '<div style="color: #dc2626; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem;">📌 PINNED</div>' : ''}
                        <div class="announcement-header" style="margin-bottom: 0.5rem;">
                            <h4 style="color: #dc2626; margin: 0; font-size: 1.1rem;">${announcement.title}</h4>
                            <small style="color: #666;">${createdDate}</small>
                        </div>
                        <div class="announcement-content" style="white-space: pre-line; line-height: 1.5; color: #333;">
                            ${content}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            announcementsContent.innerHTML = html;
        }

        // Load upcoming events
        function loadUpcomingEvents() {
            const eventsList = document.getElementById('eventsList');
            
            // Show loading state
            eventsList.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <p>Loading upcoming events...</p>
                </div>
            `;
            
            fetch('get_events.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayEvents(data.events);
                    } else {
                        eventsList.innerHTML = `
                            <div class="empty-state">
                                <p>Error loading events</p>
                                <small>${data.message}</small>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    eventsList.innerHTML = `
                        <div class="empty-state">
                            <p>Error loading events</p>
                            <small>Please try again later</small>
                        </div>
                    `;
                });
        }

        // Display all non-ended events, joined or not
        function displayEvents(events) {
            const eventsList = document.getElementById('eventsList');
            
            // Show every non-ended event, regardless of join status.
            const availableEvents = events.filter(event =>
                event.event_status === 'upcoming' || event.event_status === 'ongoing'
            );
            
            if (availableEvents.length === 0) {
                eventsList.innerHTML = `
                    <div class="empty-state">
                        <p>No upcoming events available</p>
                        <small>Events available for your cultural group and campus will appear here</small>
                    </div>
                `;
                return;
            }

            let html = '<div class="events-grid" style="display: grid; gap: 1rem;">';
            availableEvents.forEach(event => {
                const dateRange = event.is_multi_day 
                    ? `${event.formatted_start_date} - ${event.formatted_end_date}`
                    : event.formatted_start_date;
                
                const culturalGroups = Array.isArray(event.cultural_groups_array) && event.cultural_groups_array.length > 0
                    ? event.cultural_groups_array.join(', ')
                    : 'All groups';
                
                // Show join status badge
                let statusBadge = '';
                let actionButton = '';
                
                if (event.has_joined) {
                    statusBadge = '<span style="background: #28a745; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">✓ JOINED</span>';
                } else if (event.event_status === 'ongoing') {
                    statusBadge = '<span style="background: #f59e0b; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">ONGOING</span>';
                } else {
                    statusBadge = '<span style="background: #007bff; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">UPCOMING</span>';
                    actionButton = `<button class="action-btn" onclick="joinEvent(${event.id})" style="margin-left: auto; white-space: nowrap;">Join Event</button>`;
                }
                
                html += `
                    <div class="event-card" style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 1.5rem; background: white;">
                        <div class="event-header" style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <h3 style="color: #dc2626; margin: 0;">${event.title}</h3>
                                    ${statusBadge}
                                </div>
                                <div style="display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.9rem; color: #666;">
                                    <span>📅 ${dateRange}</span>
                                    <span>📍 ${event.location}</span>
                                    <span>🎭 ${event.category || 'Event'}</span>
                                </div>
                            </div>
                            ${actionButton}
                        </div>
                        <div class="event-description" style="margin-bottom: 1rem; line-height: 1.5; color: #333;">
                            ${event.description || 'No description available'}
                        </div>
                        <div class="event-groups" style="font-size: 0.9rem; color: #666; border-top: 1px solid #e0e0e0; padding-top: 0.75rem;">
                            <strong>For:</strong> ${culturalGroups}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            eventsList.innerHTML = html;
        }

        // Open costume borrowing form
        function openCostumeBorrowingForm() {
            window.location.href = 'costume-borrowing-form.php';
        }

        // Load borrowed costumes
        function loadBorrowedCostumes() {
            const loadingDiv = document.getElementById('borrowedCostumesLoading');
            const listDiv = document.getElementById('borrowedCostumesList');
            
            loadingDiv.style.display = 'block';
            listDiv.style.display = 'none';
            
            fetch('get_borrowed_costumes.php')
                .then(response => response.json())
                .then(data => {
                    loadingDiv.style.display = 'none';
                    listDiv.style.display = 'block';
                    
                    if (data.success) {
                        displayBorrowedCostumes(data.requests);
                    } else {
                        showBorrowedCostumesError(data.message);
                    }
                })
                .catch(error => {
                    loadingDiv.style.display = 'none';
                    listDiv.style.display = 'block';
                    showBorrowedCostumesError('Error loading borrowed costumes: ' + error.message);
                });
        }

        // Display borrowed costumes and all requests
        function displayBorrowedCostumes(requests) {
            const listDiv = document.getElementById('borrowedCostumesList');
            
            if (!requests || requests.length === 0) {
                listDiv.innerHTML = `
                    <div class="empty-borrowed-state">
                        <p>No borrow requests</p>
                        <small>Your borrow requests will be listed here</small>
                    </div>
                `;
                return;
            }

            let html = `
                <table class="borrowed-costumes-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Status</th>
                            <th>Request Date</th>
                            <th>Return Date</th>
                            <th>Usage Period</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            requests.forEach(request => {
                const statusClass = getRequestStatusClass(request);
                const formattedRequestDate = formatDate(request.request_date);
                const formattedDueDate = formatDate(request.due_date);
                
                // Determine if item is overdue - only after 11:59 PM on the due date
                const now = new Date();
                const dueDate = new Date(request.due_date);
                const endOfDueDate = new Date(dueDate);
                endOfDueDate.setHours(23, 59, 59, 999); // Set to 11:59:59 PM on due date
                const isOverdue = request.status === 'approved' && request.current_status === 'active' && endOfDueDate < now;
                
                // Determine actions based on status
                let actionsHtml = '';
                if (request.status === 'approved' && request.current_status === 'active') {
                    // Always show return button for active items, regardless of overdue status
                    actionsHtml = `<button class="action-btn return-btn" onclick="openReturnForm(${request.id})" style="background: #28a745; color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer;">Return Items</button>`;
                } else if (request.current_status === 'pending_return') {
                    actionsHtml = '<span style="color: #f59e0b; font-weight: 600;">Return Pending</span>';
                } else if (request.current_status === 'returned') {
                    actionsHtml = '<span style="color: #10b981; font-weight: 600;">Returned</span>';
                }
                
                // Build item name display - show approved item with quantity and requested item if different
                let itemNameDisplay = request.item_name;
                if (request.quantity) {
                    itemNameDisplay += ` (Qty: ${request.quantity})`;
                }
                if (request.requested_item && request.requested_item !== request.item_name) {
                    itemNameDisplay += `<br><small style="color: #6b7280;">Requested: ${request.requested_item}</small>`;
                }
                
                html += `
                    <tr>
                        <td class="item-name-cell">${itemNameDisplay}</td>
                        <td><span class="item-status ${statusClass}">${getRequestStatusText(request)}</span></td>
                        <td class="date-cell">${formattedRequestDate}</td>
                        <td class="date-cell">${formattedDueDate}</td>
                        <td class="date-cell">${request.dates_of_use || 'Not specified'}</td>
                        <td class="action-cell">${actionsHtml}</td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            listDiv.innerHTML = html;
        }

        // Get status class for styling
        function getRequestStatusClass(request) {
            switch(request.status) {
                case 'pending':
                    return 'status-pending';
                case 'approved':
                    if (request.current_status === 'returned') {
                        return 'status-returned';
                    }
                    if (request.due_date) {
                        const dueDate = new Date(request.due_date);
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        dueDate.setHours(0, 0, 0, 0);
                        
                        if (dueDate < today) {
                            return 'status-overdue';
                        }
                    }
                    return 'status-active';
                case 'rejected':
                    return 'status-rejected';
                default:
                    return 'status-pending';
            }
        }

        // Get status text
        function getRequestStatusText(request) {
            switch(request.status) {
                case 'pending':
                    return 'Pending Review';
                case 'approved':
                    if (request.current_status === 'returned') {
                        return 'Returned';
                    }
                    if (request.current_status === 'pending_return') {
                        return 'Return Pending';
                    }
                    if (request.current_status === 'overdue') {
                        return 'Overdue';
                    }
                    if (request.due_date) {
                        const dueDate = new Date(request.due_date);
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        dueDate.setHours(0, 0, 0, 0);
                        
                        if (dueDate < today) {
                            return 'Overdue';
                        }
                    }
                    return 'Active';
                case 'rejected':
                    return 'Rejected';
                default:
                    return request.status;
            }
        }

        // Get display category
        function getDisplayCategory(request) {
            if (request.display_type === 'approved_item') {
                return request.category.charAt(0).toUpperCase() + request.category.slice(1);
            } else {
                return 'Borrow Request';
            }
        }

        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'Not specified';
            
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Show error message
        function showBorrowedCostumesError(message) {
            const listDiv = document.getElementById('borrowedCostumesList');
            listDiv.innerHTML = `
                <div class="empty-borrowed-state">
                    <p style="color: #dc2626;">Error loading borrowed costumes</p>
                    <small>${message}</small>
                </div>
            `;
        }

        // Open return form for a specific borrowing request
        function openReturnForm(requestId) {
            console.log('Opening return form for request ID:', requestId);
            if (!requestId) {
                console.error('No request ID provided');
                alert('Error: No request ID provided');
                return;
            }
            // Navigate directly to return form
            window.location.href = 'return-form.php?request_id=' + requestId;
        }

        // Join event function
        function joinEvent(eventId) {
            if (!confirm('Are you sure you want to join this event?')) {
                return;
            }

            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Joining...';

            fetch('join_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event_id: eventId,
                    action: 'join'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    sessionStorage.setItem('lastProfileSaveResponse', JSON.stringify(data));
                    // Show success message
                    alert('Successfully joined the event!');
                    // Reload events to update the display
                    loadUpcomingEvents();
                    // Reload dashboard stats and panels
                    loadDashboardStats();
                } else {
                    alert('Error joining event: ' + data.message);
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error joining event. Please try again.');
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        // Leave event function
        function leaveEvent(eventId) {
            if (!confirm('Are you sure you want to leave this event?')) {
                return;
            }

            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Leaving...';

            fetch('join_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event_id: eventId,
                    action: 'leave'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alert('Successfully left the event.');
                    // Reload events to update the display
                    loadUpcomingEvents();
                } else {
                    alert('Error leaving event: ' + data.message);
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error leaving event. Please try again.');
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        // Performance Record Functions
        function loadPerformanceRecord() {
            loadCertificates();
            loadParticipatedEvents();
        }

        // Certificate Upload Functions
        function openCertificateUploadModal() {
            const modal = document.getElementById('certificateUploadModal');
            modal.style.display = 'flex';
            modal.style.overflowY = 'auto';
            modal.style.padding = '2rem 0';
            modal.style.justifyContent = 'unset';
            modal.style.alignItems = 'unset';
            
            // Update the modal content container for scrollability
            const modalContent = modal.querySelector('div');
            modalContent.style.margin = 'auto';
            modalContent.style.maxHeight = '90vh';
            modalContent.style.overflowY = 'auto';
            modalContent.style.overflow = 'auto';
            
            // Reset form
            document.getElementById('certificateUploadForm').reset();
            preventBackgroundScroll();
        }

        function closeCertificateUploadModal() {
            const modal = document.getElementById('certificateUploadModal');
            modal.style.display = 'none';
            allowBackgroundScroll();
        }

        // Handle certificate upload form submission
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('certificateUploadForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    uploadCertificate();
                });
            }

            // Handle evaluation form submission
            const evaluationForm = document.getElementById('eventEvaluationForm');
            if (evaluationForm) {
                evaluationForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitEventEvaluation();
                });
            }
        });

        function uploadCertificate() {
            const form = document.getElementById('certificateUploadForm');
            const formData = new FormData();
            
            const title = document.getElementById('certificateTitle').value;
            const description = document.getElementById('certificateDescription').value;
            const date = document.getElementById('certificateDate').value;
            const file = document.getElementById('certificateFile').files[0];
            
            if (!title || !date || !file) {
                alert('Please fill in all required fields');
                return;
            }
            
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
            const allowedExtensions = ['.jpg', '.jpeg', '.png', '.pdf'];
            const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
            
            if (!allowedTypes.includes(file.type) && !allowedExtensions.includes(fileExtension)) {
                alert('Invalid file type. Please upload a PDF, JPG, or PNG file.');
                return;
            }
            
            // Check file size (5MB limit)
            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                return;
            }
            
            formData.append('title', title);
            formData.append('description', description);
            formData.append('date', date);
            formData.append('certificate_file', file);
            
            // Show loading state
            const submitBtn = form.querySelector('[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Uploading...';
            submitBtn.disabled = true;
            
            fetch('upload_certificate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Certificate uploaded successfully!');
                    closeCertificateUploadModal();
                    loadCertificates(); // Reload certificates
                } else {
                    alert('Error uploading certificate: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error uploading certificate. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        function loadCertificates() {
            const container = document.getElementById('certificatesContainer');
            const loading = document.getElementById('certificatesLoading');
            
            loading.style.display = 'block';
            container.innerHTML = '';
            
            fetch('get_student_certificates.php')
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    displayCertificates(data.certificates || []);
                })
                .catch(error => {
                    console.error('Error:', error);
                    loading.style.display = 'none';
                    container.innerHTML = '<p style="color: #dc2626; text-align: center; padding: 2rem;">Error loading certificates</p>';
                });
        }

        function displayCertificates(certificates) {
            const container = document.getElementById('certificatesContainer');
            
            if (certificates.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <p>No certificates uploaded</p>
                        <small>Upload your first certificate to get started</small>
                    </div>
                `;
                return;
            }
            
            let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">';
            certificates.forEach(cert => {
                const fileExtension = cert.file_path.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png'].includes(fileExtension);
                
                html += `
                    <div style="border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="aspect-ratio: 16/9; background: #f8f9fa; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                            ${isImage ? 
                                `<img src="${cert.file_path}" alt="${cert.title}" style="width: 100%; height: 100%; object-fit: cover;">` :
                                `<div style="text-align: center; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📄</div>
                                    <div>PDF Certificate</div>
                                </div>`
                            }
                        </div>
                        <div style="padding: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0; color: #333; font-size: 1rem;">${cert.title}</h4>
                            ${cert.description ? `<p style="margin: 0 0 0.5rem 0; color: #666; font-size: 0.9rem;">${cert.description}</p>` : ''}
                            <div style="color: #888; font-size: 0.8rem; margin-bottom: 1rem;">
                                Date: ${new Date(cert.date_received).toLocaleDateString()}
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button onclick="viewCertificate('${cert.file_path}')" style="flex: 1; padding: 0.5rem; border: 1px solid #dc2626; background: white; color: #dc2626; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">
                                    View
                                </button>
                                <button onclick="deleteCertificate(${cert.id})" style="padding: 0.5rem; border: 1px solid #dc3545; background: #dc3545; color: white; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        function viewCertificate(filePath) {
            console.log('Viewing certificate:', filePath); // Debug log
            
            const fileExtension = filePath.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png'].includes(fileExtension);
            
            if (isImage) {
                // Show image in modal
                showImageModal(filePath);
            } else {
                // Open PDF in new tab
                window.open(filePath, '_blank');
            }
        }

        function showImageModal(imagePath) {
            // Create modal if it doesn't exist
            let modal = document.getElementById('imageViewModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'imageViewModal';
                modal.style.cssText = `
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.8);
                    z-index: 2000;
                    justify-content: center;
                    align-items: center;
                    padding: 2rem;
                `;
                
                modal.innerHTML = `
                    <div style="position: relative; max-width: 90%; max-height: 90%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                        <div style="position: absolute; top: 10px; right: 10px; z-index: 10;">
                            <button onclick="closeImageModal()" style="background: rgba(0,0,0,0.7); color: white; border: none; border-radius: 50%; width: 40px; height: 40px; font-size: 1.5rem; cursor: pointer; display: flex; align-items: center; justify-content: center;">&times;</button>
                        </div>
                        <img id="modalImage" style="max-width: 100%; max-height: 90vh; object-fit: contain; display: block;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <div style="display: none; padding: 2rem; text-align: center; color: #666;">
                            <p>Unable to load image</p>
                            <small>The certificate file may not exist or is not accessible</small>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Close modal when clicking outside
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeImageModal();
                    }
                });
            }
            
            // Set image source and show modal
            const modalImage = document.getElementById('modalImage');
            const errorDiv = modalImage.nextElementSibling;
            
            // Reset states
            modalImage.style.display = 'block';
            errorDiv.style.display = 'none';
            
            // Add loading state
            modalImage.style.opacity = '0.5';
            modalImage.onload = function() {
                this.style.opacity = '1';
                console.log('Image loaded successfully');
            };
            modalImage.onerror = function() {
                console.error('Failed to load image:', imagePath);
                this.style.display = 'none';
                errorDiv.style.display = 'block';
            };
            
            modalImage.src = imagePath;
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageViewModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        function deleteCertificate(certificateId) {
            if (!confirm('Are you sure you want to delete this certificate?')) {
                return;
            }
            
            fetch('delete_certificate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ certificate_id: certificateId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Certificate deleted successfully!');
                    loadCertificates(); // Reload certificates
                } else {
                    alert('Error deleting certificate: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting certificate. Please try again.');
            });
        }

        function loadParticipatedEvents() {
            const container = document.getElementById('eventsContainer');
            const loading = document.getElementById('eventsLoading');
            
            loading.style.display = 'block';
            container.innerHTML = '';
            
            fetch('get_student_events.php')
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    displayParticipatedEvents(data.events || []);
                })
                .catch(error => {
                    console.error('Error:', error);
                    loading.style.display = 'none';
                    container.innerHTML = '<p style="color: #dc2626; text-align: center; padding: 2rem;">Error loading events</p>';
                });
        }

        function displayParticipatedEvents(events) {
            const container = document.getElementById('eventsContainer');
            
            if (events.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <p>No events participated</p>
                        <small>Your event participation history will appear here</small>
                    </div>
                `;
                return;
            }
            
            let html = `
                <table class="borrowed-costumes-table">
                    <thead>
                        <tr>
                            <th>EVENT NAME</th>
                            <th>STATUS</th>
                            <th>EVENT DATE</th>
                            <th>LOCATION</th>
                            <th>CATEGORY</th>
                            <th>JOINED</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            events.forEach(event => {
                const dateRange = event.is_multi_day 
                    ? `${event.formatted_start_date} - ${event.formatted_end_date}`
                    : event.formatted_start_date;
                
                const statusClass = event.date_status === 'completed' ? 'status-returned' : 
                                   event.date_status === 'ongoing' ? 'status-active' : 'status-pending';
                
                const joinedDate = new Date(event.joined_date).toLocaleDateString();
                
                // Determine if evaluate button should be shown
                let evaluateButtonHtml = '';
                if (event.date_status === 'completed') {
                    if (event.has_evaluation) {
                        evaluateButtonHtml = '<span class="item-status" style="background: #d1fae5; color: #065f46;">Evaluated</span>';
                    } else {
                        evaluateButtonHtml = `<button class="action-btn" style="background: #17a2b8; padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="openEvaluationForm(${event.id}, '${event.title}')">Evaluate</button>`;
                    }
                } else {
                    evaluateButtonHtml = '<span style="color: #6c757d; font-size: 0.8rem;">Not Available</span>';
                }
                
                html += `
                    <tr>
                        <td class="item-name-cell">${event.title}</td>
                        <td><span class="item-status ${statusClass}">${event.date_status.toUpperCase()}</span></td>
                        <td class="date-cell">${dateRange}</td>
                        <td>${event.location}</td>
                        <td class="item-category-cell">${event.category}</td>
                        <td class="date-cell">${joinedDate}</td>
                        <td class="action-cell">${evaluateButtonHtml}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }

        // Load performance record when section is activated
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    const section = this.dataset.section;
                    if (section === 'performance-record') {
                        setTimeout(() => loadPerformanceRecord(), 100);
                    }
                });
            });
            
            // Load if initially on performance record page
            const urlParams = new URLSearchParams(window.location.search);
            const activeSection = urlParams.get('section');
            if (activeSection === 'performance-record') {
                setTimeout(() => loadPerformanceRecord(), 100);
            }
        });

        // Load dashboard statistics
        function loadDashboardStats() {
            fetch('get_dashboard_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateDashboardCards(data.stats);
                        loadDashboardAnnouncements();
                        loadDashboardEvents();
                    } else {
                        console.error('Dashboard stats error:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading dashboard stats:', error);
                });
        }

        function updateDashboardCards(stats) {
            // Update card values
            const cards = document.querySelectorAll('.stat-card .stat-number');
            if (cards.length >= 4) {
                cards[0].textContent = stats.new_announcements;
                cards[1].textContent = stats.upcoming_events; 
                cards[2].textContent = stats.total_performances;
                cards[3].textContent = stats.borrowed_costumes;
            }
        }

        // Load recent announcements for dashboard
        function loadDashboardAnnouncements() {
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.announcements && data.announcements.length > 0) {
                        const panel = document.querySelector('#dashboard .content-panel:nth-child(1) .panel-content');
                        const recentAnnouncements = data.announcements.slice(0, 3);
                        
                        let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
                        recentAnnouncements.forEach(announcement => {
                            const content = announcement.content || announcement.message || 'No content';
                            const shortContent = content.length > 100 ? content.substring(0, 100) + '...' : content;
                            const date = new Date(announcement.created_at).toLocaleDateString();
                            
                            html += `
                                <div style="border-left: 3px solid #dc2626; padding-left: 0.75rem;">
                                    <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">${announcement.title}</div>
                                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">${shortContent}</div>
                                    <div style="font-size: 0.75rem; color: #999;">${date}</div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        panel.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error loading dashboard announcements:', error);
                });
        }

        // Load upcoming events for dashboard (only events student has JOINED and not yet ended)
        function loadDashboardEvents() {
            fetch('get_events.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.events && data.events.length > 0) {
                        const panel = document.querySelector('#dashboard .content-panel:nth-child(2) .panel-content');
                        // Filter for events that student HAS JOINED and haven't ended yet
                        const joinedEvents = data.events.filter(e => 
                            e.has_joined && e.event_status !== 'ended'
                        ).slice(0, 2);
                        
                        if (joinedEvents.length > 0) {
                            let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
                            joinedEvents.forEach(event => {
                                const culturalGroups = Array.isArray(event.cultural_groups_array) 
                                    ? event.cultural_groups_array.join(', ') 
                                    : 'All groups';
                                html += `
                                    <div style="border-left: 3px solid #dc2626; padding-left: 0.75rem;">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">${event.title}</div>
                                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">
                                            📅 ${event.formatted_start_date}
                                        </div>
                                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">
                                            📍 ${event.location || 'TBA'}
                                        </div>
                                        <div style="font-size: 0.75rem; color: #999;">
                                            ${culturalGroups}
                                        </div>
                                    </div>
                                `;
                            });
                            html += '</div>';
                            panel.innerHTML = html;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading dashboard events:', error);
                });
        }

        // Load costume returns for dashboard (active borrowed items)
        function loadDashboardCostumeReturns() {
            fetch('get_borrowed_costumes.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.requests && data.requests.length > 0) {
                        const panel = document.querySelector('#dashboard .content-panel:nth-child(3) .panel-content');
                        // Filter for active borrowed items
                        const activeBorrows = data.requests.filter(request => 
                            request.status === 'approved' && request.current_status === 'active'
                        ).slice(0, 3);
                        
                        if (activeBorrows.length > 0) {
                            let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
                            activeBorrows.forEach(request => {
                                const dueDate = new Date(request.due_date);
                                const now = new Date();
                                const endOfDueDate = new Date(dueDate);
                                endOfDueDate.setHours(23, 59, 59, 999);
                                const isOverdue = endOfDueDate < now;
                                const dueDateText = formatDate(request.due_date);
                                
                                const itemName = request.approved_item_name || request.item_name || 'Item';
                                const quantity = request.approved_quantity || request.quantity || 1;
                                
                                html += `
                                    <div style="border-left: 3px solid ${isOverdue ? '#dc2626' : '#dc2626'}; padding-left: 0.75rem;">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">${itemName}</div>
                                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">
                                            Qty: ${quantity} | Due: ${dueDateText}
                                        </div>
                                        <div style="font-size: 0.75rem; color: ${isOverdue ? '#dc2626' : '#dc2626'};">
                                            ${isOverdue ? 'OVERDUE' : 'Active'}
                                        </div>
                                    </div>
                                `;
                            });
                            html += '</div>';
                            panel.innerHTML = html;
                        } else {
                            panel.innerHTML = `
                                <div class="empty-state">
                                    <p>No costume returns due</p>
                                    <small>Your borrowed costumes will appear here</small>
                                </div>
                            `;
                        }
                    } else {
                        const panel = document.querySelector('#dashboard .content-panel:nth-child(3) .panel-content');
                        panel.innerHTML = `
                            <div class="empty-state">
                                <p>No costume returns due</p>
                                <small>Your borrowed costumes will appear here</small>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading dashboard costume returns:', error);
                    const panel = document.querySelector('#dashboard .content-panel:nth-child(3) .panel-content');
                    panel.innerHTML = `
                        <div class="empty-state">
                            <p>Error loading costume returns</p>
                            <small>Please refresh the page</small>
                        </div>
                    `;
                });
        }

        // Load stats when dashboard is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Load stats after a short delay to ensure DOM is ready
            setTimeout(loadDashboardStats, 500);
            // Load dashboard content panels
            setTimeout(loadDashboardAnnouncements, 700);
            setTimeout(loadDashboardEvents, 800);
            setTimeout(loadDashboardCostumeReturns, 900);
        });

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('certificateUploadModal');
            if (event.target === modal) {
                closeCertificateUploadModal();
            }
        });

        // Event evaluation function
        function openEvaluationForm(eventId, eventTitle) {
            // Set the event title and ID in the modal
            document.getElementById('evaluationEventTitle').textContent = eventTitle;
            document.getElementById('evaluationEventId').value = eventId;
            
            // Reset the form
            document.getElementById('eventEvaluationForm').reset();
            document.getElementById('evaluationEventId').value = eventId; // Set again after reset
            
            // Show the modal
            const modal = document.getElementById('eventEvaluationModal');
            modal.style.display = 'block';
            preventBackgroundScroll(); // Prevent background scrolling
        }

        // Close evaluation modal
        function closeEventEvaluationModal() {
            const modal = document.getElementById('eventEvaluationModal');
            modal.style.display = 'none';
            allowBackgroundScroll(); // Restore scrolling
        }

        // Submit event evaluation
        function submitEventEvaluation() {
            const form = document.getElementById('eventEvaluationForm');
            const formData = new FormData(form);
            
            // Convert FormData to JSON for easier handling
            const evaluationData = {
                event_id: formData.get('event_id'),
                q1_rating: formData.get('q1'),
                q2_rating: formData.get('q2'),
                q3_rating: formData.get('q3'),
                q4_rating: formData.get('q4'),
                q5_rating: formData.get('q5'),
                q6_rating: formData.get('q6'),
                q7_rating: formData.get('q7'),
                q8_rating: formData.get('q8'),
                q9_rating: formData.get('q9'),
                q10_rating: formData.get('q10'),
                q11_rating: formData.get('q11'),
                q12_rating: formData.get('q12'),
                q13_opinion: formData.get('q13_opinion'),
                q14_suggestions: formData.get('q14_suggestions'),
                q15_comments: formData.get('q15_comments')
            };

            // Validate that all Likert scale questions (1-12) are answered
            for (let i = 1; i <= 12; i++) {
                if (!evaluationData[`q${i}_rating`]) {
                    alert(`Please answer question ${i} before submitting.`);
                    return;
                }
            }
            
            // Validate open-ended questions
            if (!evaluationData.q13_opinion || evaluationData.q13_opinion.trim() === '') {
                alert('Please answer question 13 (Was the training helpful for you in the practice of your profession?).');
                return;
            }
            
            if (!evaluationData.q14_suggestions || evaluationData.q14_suggestions.trim() === '') {
                alert('Please answer question 14 (What aspect of the training has been helpful to you?).');
                return;
            }
            
            if (!evaluationData.q15_comments || evaluationData.q15_comments.trim() === '') {
                alert('Please provide your comments/recommendations/complaints in question 15.');
                return;
            }

            // Show loading state
            const submitBtn = form.querySelector('[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Submitting...';
            submitBtn.disabled = true;

            // Submit evaluation
            fetch('submit_event_evaluation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(evaluationData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Thank you! Your evaluation has been submitted successfully.');
                    closeEventEvaluationModal();
                    // Optionally reload events to show updated status
                    loadParticipatedEvents();
                } else {
                    alert('Error submitting evaluation: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting evaluation. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Load and display performer profile form
        function loadPerformerProfile() {
            const profileLoading = document.getElementById('profileLoading');
            const profileError = document.getElementById('profileError');
            const performerProfileView = document.getElementById('performerProfileView');

            console.log('Loading performer profile...');

            // Show loading state
            profileLoading.style.display = 'block';
            profileError.style.display = 'none';
            performerProfileView.style.display = 'none';

            fetch('get_application_data.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Application data response:', data);
                    profileLoading.style.display = 'none';
                    
                    if (data.success) {
                        displayPerformerProfile(data.application, data.participations, data.competitions, data.affiliations);
                        performerProfileView.style.display = 'block';
                        // Show export button when profile is available
                        document.getElementById('exportProfileBtn').style.display = 'inline-block';
                    } else {
                        console.error('Error loading profile:', data.message, data.debug);
                        profileError.style.display = 'block';
                        // Hide export button when no profile data
                        document.getElementById('exportProfileBtn').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error loading profile:', error);
                    profileLoading.style.display = 'none';
                    profileError.style.display = 'block';
                    // Hide export button on error
                    document.getElementById('exportProfileBtn').style.display = 'none';
                });
        }

        // Display performer profile form
        function displayPerformerProfile(app, participations, competitions, affiliations) {
            const performerProfileView = document.getElementById('performerProfileView');
            
            console.log('Displaying profile, photo path:', app.profile_photo);
            
            // Get cultural group - this is the type of performance
            const culturalGroup = app.cultural_group || 'Not specified';

            // Create participation table rows
            let participationRows = '';
            if (participations && participations.length > 0) {
                participations.forEach(p => {
                    participationRows += `
                        <tr data-participation-id="${p.id || ''}">
                            <td class="editable-participation-date">${p.participation_date || ''}</td>
                            <td class="editable-participation-event">${p.activity_title || ''}</td>
                            <td class="editable-participation-level">${p.level || ''}</td>
                            <td class="editable-participation-rank">${p.rank_award || ''}</td>
                            <td class="edit-column" style="display: none;">
                                <button class="delete-row-btn" onclick="deleteParticipationRow(${p.id || 0})">Delete</button>
                            </td>
                        </tr>
                    `;
                });
            } else {
                participationRows = '<tr><td colspan="5" style="text-align: center; color: #666;">No participation records</td></tr>';
            }

            // Create competition table rows
            let competitionRows = '';
            if (competitions && competitions.length > 0) {
                competitions.forEach(c => {
                    competitionRows += `
                        <tr data-competition-id="${c.id || ''}">
                            <td class="editable-competition-date">${c.competition_date || ''}</td>
                            <td class="editable-competition-event">${c.event_name || ''}</td>
                            <td class="editable-competition-level">${c.level || ''}</td>
                            <td class="editable-competition-rank">${c.rank_award || ''}</td>
                            <td class="edit-column" style="display: none;">
                                <button class="delete-row-btn" onclick="deleteCompetitionRow(${c.id || 0})">Delete</button>
                            </td>
                        </tr>
                    `;
                });
            } else {
                competitionRows = '<tr><td colspan="5" style="text-align: center; color: #666;">No competition records</td></tr>';
            }

            // Create affiliation table rows
            let affiliationRows = '';
            if (affiliations && affiliations.length > 0) {
                affiliations.forEach(a => {
                    affiliationRows += `
                        <tr data-affiliation-id="${a.id || ''}">
                            <td class="editable-affiliation-position">${a.affiliation_position || ''}</td>
                            <td class="editable-affiliation-org">${a.organization_name || ''}</td>
                            <td class="editable-affiliation-year">${a.year || ''}</td>
                            <td class="edit-column" style="display: none;">
                                <button class="delete-row-btn" onclick="deleteAffiliationRow(${a.id || 0})">Delete</button>
                            </td>
                        </tr>
                    `;
                });
            } else {
                affiliationRows = '<tr><td colspan="4" style="text-align: center; color: #666;">No affiliation records</td></tr>';
            }

            const html = `
                <div class="performer-profile-card">
                    <div class="form-header" style="text-align: center; padding: 2rem 2rem 1rem 2rem; border-bottom: 2px solid #333;">
                        <h1 class="form-title" style="font-size: 1.8rem; font-weight: 700; color: #333; margin: 0;">PERFORMER'S PROFILE FORM</h1>
                    </div>
                    <div class="form-body">
                        <!-- Cultural Group / Type of Performance Section -->
                        <div class="form-section">
                            <h3 class="section-title">CULTURAL GROUP / TYPE OF PERFORMANCE</h3>
                            <div class="form-value" style="font-size: 1.1rem; font-weight: 600; color: #333; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
                                ${culturalGroup}
                            </div>
                        </div>

                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h3 class="section-title">PERSONAL INFORMATION</h3>
                            
                            <div class="photo-section">
                                <div class="photo-placeholder" id="profilePhotoContainer">
                                    ${app.profile_photo ? `<img src="../${app.profile_photo}?v=${Date.now()}" alt="Profile Photo" id="profilePhotoImg">` : '<span id="profilePhotoPlaceholder">Passport Size Photo</span>'}
                                </div>
                                <div class="photo-edit-controls" id="photoEditControls">
                                    <input type="file" id="photoUploadInput" accept="image/jpeg,image/jpg,image/png,image/jfif" style="display: none;">
                                    <button class="photo-upload-btn" onclick="document.getElementById('photoUploadInput').click()">📷 Upload Photo</button>
                                    <button class="photo-delete-btn" onclick="deleteProfilePhoto()">🗑️ Delete Photo</button>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-row">
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>First Name</label>
                                        <div class="form-value" id="profile_firstName">${app.first_name || '&nbsp;'}</div>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>Middle Name</label>
                                        <div class="form-value" id="profile_middleName">${app.middle_name || '&nbsp;'}</div>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 0;">
                                        <label>Last Name</label>
                                        <div class="form-value" id="profile_lastName">${app.last_name || '&nbsp;'}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Permanent Address</label>
                                    <div class="form-value" id="profile_permanentAddress">${app.address || ''}</div>
                                </div>

                                <div class="form-group">
                                    <label>Present Address</label>
                                    <div class="form-value" id="profile_presentAddress">${app.present_address || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Date of Birth</label>
                                        <div class="form-value" id="profile_dateOfBirth">${app.date_of_birth || ''}</div>
                                    </div>
                                    <div class="form-group quarter">
                                        <label>Age</label>
                                        <div class="form-value" id="profile_age">${app.age || ''}</div>
                                    </div>
                                    <div class="form-group quarter">
                                        <label>Gender</label>
                                        <div class="form-value" id="profile_gender">${app.gender || ''}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Place of Birth</label>
                                    <div class="form-value" id="profile_placeOfBirth">${app.place_of_birth || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Email Address</label>
                                        <div class="form-value" id="profile_email">${app.email || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Contact Number</label>
                                        <div class="form-value" id="profile_contactNumber">${app.contact_number || ''}</div>
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
                                    <div class="form-value" id="profile_fatherName">${app.father_name || ''}</div>
                                </div>

                                <div class="form-group">
                                    <label>Mother's Name</label>
                                    <div class="form-value" id="profile_motherName">${app.mother_name || ''}</div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Guardian</label>
                                        <div class="form-value" id="profile_guardian">${app.guardian || 'N/A'}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Guardian Contact</label>
                                        <div class="form-value" id="profile_guardianContact">${app.guardian_contact || 'N/A'}</div>
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
                                        <div class="form-value" id="profile_campus">${app.campus || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>College</label>
                                        <div class="form-value" id="profile_college">${app.college || ''}</div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>SR-Code</label>
                                        <div class="form-value" id="profile_srCode">${app.sr_code || ''}</div>
                                    </div>
                                    <div class="form-group half">
                                        <label>Year Level</label>
                                        <div class="form-value" id="profile_yearLevel">${app.year_level || ''}</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Program/Course</label>
                                    <div class="form-value" id="profile_program">${app.program || ''}</div>
                                </div>

                                <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1rem;">
                                    <span style="font-size: 0.9rem; color: #333;">Number of Units Enrolled:</span>
                                    <div style="display: flex; gap: 1rem; align-items: center;">
                                        <span style="font-size: 0.85rem;">1st Semester:</span>
                                        <span id="profile_firstSemesterUnits" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa; min-width: 60px; text-align: center;">${app.first_semester_units || 0}</span>
                                        <span style="font-size: 0.85rem; margin-left: 1rem;">2nd Semester:</span>
                                        <span id="profile_secondSemesterUnits" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa; min-width: 60px; text-align: center;">${app.second_semester_units || 0}</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Instructors</label>
                                    <div class="form-value" id="profile_instructors">${app.instructors || 'Not specified'}</div>
                                    <small id="instructors_note" style="display: none; color: #666; font-size: 0.85rem; font-style: italic; margin-top: 0.25rem;">Separate each name with a comma</small>
                                </div>
                            </div>
                        </div>

                        <!-- Participation Section -->
                        <div class="form-section">
                            <h3 class="section-title">III. PARTICIPATION IN THE FIELD OF INTEREST / ACHIEVEMENTS</h3>
                            <table class="participation-table" id="participationTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Level <span class="sub-text">(School, Municipal, Provincial, Regional, National, International)</span></th>
                                        <th>Rank (Place)</th>
                                        <th class="edit-column" style="display: none;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="participationTableBody">
                                    ${participationRows}
                                </tbody>
                            </table>
                            <div class="table-edit-controls" id="participationControls">
                                <button class="action-btn" onclick="addParticipationRow()">➕ Add Row</button>
                            </div>
                        </div>

                        <!-- Competition Section -->
                        <div class="form-section">
                            <h3 class="section-title">IV. COMPETITION PARTICIPATED</h3>
                            <table class="participation-table" id="competitionTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Level <span class="sub-text">(School, Municipal, Provincial, Regional, National, International)</span></th>
                                        <th>Rank (Place)</th>
                                        <th class="edit-column" style="display: none;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="competitionTableBody">
                                    ${competitionRows}
                                </tbody>
                            </table>
                            <div class="table-edit-controls" id="competitionControls">
                                <button class="action-btn" onclick="addCompetitionRow()">➕ Add Row</button>
                            </div>
                        </div>

                        <!-- Affiliation Section -->
                        <div class="form-section">
                            <h3 class="section-title">V. AFFILIATION TO ORGANIZATIONS</h3>
                            <table class="affiliation-table" id="affiliationTable">
                                <thead>
                                    <tr>
                                        <th>Position</th>
                                        <th>Name of Organization</th>
                                        <th>Inclusive Years <span class="sub-text">(e.g., 2020-2023)</span></th>
                                        <th class="edit-column" style="display: none;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="affiliationTableBody">
                                    ${affiliationRows}
                                </tbody>
                            </table>
                            <div class="table-edit-controls" id="affiliationControls">
                                <button class="action-btn" onclick="addAffiliationRow()">➕ Add Row</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            performerProfileView.innerHTML = html;
            
            // Attach photo upload event listener AFTER HTML is rendered
            const photoUploadInput = document.getElementById('photoUploadInput');
            if (photoUploadInput) {
                console.log('✅ Photo upload input found after render, attaching event listener');
                photoUploadInput.addEventListener('change', function(event) {
                    console.log('📁 File input changed, files:', event.target.files);
                    if (event.target.files && event.target.files[0]) {
                        uploadProfilePhoto(event.target.files[0]);
                    } else {
                        console.log('❌ No file selected');
                    }
                });
            } else {
                console.log('❌ Photo upload input STILL NOT found after render!');
            }
        }

        // Export Profile to PDF Function
        function exportProfileToPDF() {
            // Show loading indicator
            const exportBtn = document.getElementById('exportProfileBtn');
            const originalText = exportBtn.innerHTML;
            exportBtn.innerHTML = '⏳ Generating PDF...';
            exportBtn.disabled = true;

            // Open the export URL in a new window to trigger download
            window.location.href = 'export_profile_pdf.php';

            // Reset button after a delay
            setTimeout(() => {
                exportBtn.innerHTML = originalText;
                exportBtn.disabled = false;
            }, 2000);
        }

        // Edit Profile Functions
        let isEditMode = false;
        let originalProfileData = {};
        
        // Pending changes tracking
        let pendingChanges = {
            participation: {
                toAdd: [],
                toDelete: []
            },
            competition: {
                toAdd: [],
                toDelete: []
            },
            affiliation: {
                toAdd: [],
                toDelete: []
            },
            photo: {
                action: null, // 'upload' or 'delete'
                file: null,
                preview: null
            }
        };

        function requestEditProfile() {
            // Show password verification modal
            document.getElementById('passwordVerificationModal').style.display = 'block';
            document.getElementById('verifyPassword').value = '';
            document.getElementById('passwordError').style.display = 'none';
        }

        function closePasswordModal() {
            document.getElementById('passwordVerificationModal').style.display = 'none';
            document.getElementById('verifyPassword').value = '';
            document.getElementById('passwordError').style.display = 'none';
        }

        function verifyPassword() {
            const password = document.getElementById('verifyPassword').value;
            const errorDiv = document.getElementById('passwordError');

            if (!password) {
                errorDiv.textContent = 'Please enter your password';
                errorDiv.style.display = 'block';
                return;
            }

            // Show loading state
            errorDiv.textContent = 'Verifying...';
            errorDiv.style.color = '#666';
            errorDiv.style.display = 'block';

            // Verify password via API
            fetch('verify_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ password: password })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Password correct - enable edit mode
                    closePasswordModal();
                    enableEditMode();
                } else {
                    // Password incorrect
                    errorDiv.textContent = data.message || 'Incorrect password. Please try again.';
                    errorDiv.style.color = '#dc2626';
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.textContent = 'Error verifying password. Please try again.';
                errorDiv.style.color = '#dc2626';
                errorDiv.style.display = 'block';
            });
        }

        function enableEditMode() {
            isEditMode = true;

            // Show save and cancel buttons, hide edit button
            document.getElementById('editProfileBtn').style.display = 'none';
            const saveBtn = document.getElementById('saveProfileBtn');
            saveBtn.style.display = 'inline-block';
            saveBtn.textContent = 'Save Profile';
            saveBtn.disabled = false;
            document.getElementById('cancelEditBtn').style.display = 'inline-block';

            // Make fields editable
            const editableFields = [
                'firstName', 'middleName', 'lastName',
                'dateOfBirth', 'age', 'gender', 'placeOfBirth',
                'email', 'contactNumber', 'permanentAddress', 'presentAddress',
                'fatherName', 'motherName', 'guardian', 'guardianContact',
                'campus', 'college', 'srCode', 'yearLevel', 'program',
                'firstSemesterUnits', 'secondSemesterUnits', 'instructors'
            ];

            // Save original data
            originalProfileData = {};
            editableFields.forEach(field => {
                const element = document.getElementById(`profile_${field}`);
                if (element) {
                    originalProfileData[field] = element.textContent;
                    element.contentEditable = 'true';
                    element.classList.add('editable-field');
                    element.style.border = '2px solid #dc2626';
                    element.style.background = '#fff';
                }
            });

            // Show photo edit controls
            const photoControls = document.getElementById('photoEditControls');
            if (photoControls) {
                photoControls.classList.add('active');
            }

            // Show instructors note
            const instructorsNote = document.getElementById('instructors_note');
            if (instructorsNote) {
                instructorsNote.style.display = 'block';
            }

            // Show table edit controls
            document.getElementById('participationControls').classList.add('active');
            document.getElementById('competitionControls').classList.add('active');
            document.getElementById('affiliationControls').classList.add('active');

            // Show delete column in tables
            document.querySelectorAll('.edit-column').forEach(el => {
                el.style.display = 'table-cell';
            });

            // Show notification
            alert('✏️ Edit mode enabled! You can now modify your profile information, add/delete records, and update your photo.');
        }

        function cancelEdit() {
            if (!confirm('Are you sure you want to cancel? All unsaved changes will be lost.')) {
                return;
            }

            isEditMode = false;
            
            // Clear pending changes including photo
            pendingChanges = {
                participation: { toAdd: [], toDelete: [] },
                competition: { toAdd: [], toDelete: [] },
                affiliation: { toAdd: [], toDelete: [] },
                photo: { action: null, file: null, preview: null }
            };

            // Restore original data
            Object.keys(originalProfileData).forEach(field => {
                const element = document.getElementById(`profile_${field}`);
                if (element) {
                    element.textContent = originalProfileData[field];
                    element.contentEditable = 'false';
                    element.classList.remove('editable-field');
                    element.style.border = '2px solid transparent';
                    element.style.background = '#f8f9fa';
                }
            });

            // Hide photo edit controls
            const photoControls = document.getElementById('photoEditControls');
            if (photoControls) {
                photoControls.classList.remove('active');
            }
            
            // Reset photo button states
            const uploadBtn = document.querySelector('.photo-upload-btn');
            const deleteBtn = document.querySelector('.photo-delete-btn');
            if (uploadBtn) {
                uploadBtn.textContent = '📷 Upload Photo';
                uploadBtn.style.backgroundColor = '';
            }
            if (deleteBtn) {
                deleteBtn.textContent = '🗑️ Delete Photo';
                deleteBtn.style.backgroundColor = '';
                deleteBtn.style.color = '';
            }

            // Hide table edit controls
            document.getElementById('participationControls').classList.remove('active');
            document.getElementById('competitionControls').classList.remove('active');
            document.getElementById('affiliationControls').classList.remove('active');

            // Hide instructors note
            const instructorsNote = document.getElementById('instructors_note');
            if (instructorsNote) {
                instructorsNote.style.display = 'none';
            }

            // Hide delete column in tables
            document.querySelectorAll('.edit-column').forEach(el => {
                el.style.display = 'none';
            });

            // Show edit button, hide save and cancel buttons
            document.getElementById('editProfileBtn').style.display = 'inline-block';
            document.getElementById('saveProfileBtn').style.display = 'none';
            document.getElementById('cancelEditBtn').style.display = 'none';
            
            // Reload profile to restore any table changes
            loadPerformerProfile();
        }

        function saveProfile() {
            // Show save confirmation modal
            document.getElementById('saveConfirmationModal').style.display = 'block';
        }

        function closeSaveModal() {
            document.getElementById('saveConfirmationModal').style.display = 'none';
        }

        function confirmSave() {
            // Capture any new rows that were typed but not added to pending changes
            const collectNewRows = () => {
                document.querySelectorAll('#participationTableBody tr[data-new="true"]:not(.pending-row)').forEach(row => {
                    const data = {
                        date: row.querySelector('.participation-date')?.value || '',
                        event_name: row.querySelector('.participation-event')?.value || '',
                        level: row.querySelector('.participation-level')?.value || '',
                        rank_award: row.querySelector('.participation-rank')?.value || ''
                    };
                    if (data.date && data.event_name) {
                        pendingChanges.participation.toAdd.push(data);
                        row.classList.add('pending-row');
                        row.querySelectorAll('input, select').forEach(input => input.disabled = true);
                    }
                });

                document.querySelectorAll('#competitionTableBody tr[data-new="true"]:not(.pending-row)').forEach(row => {
                    const data = {
                        date: row.querySelector('.competition-date')?.value || '',
                        event_name: row.querySelector('.competition-event')?.value || '',
                        level: row.querySelector('.competition-level')?.value || '',
                        rank_award: row.querySelector('.competition-rank')?.value || ''
                    };
                    if (data.date && data.event_name) {
                        pendingChanges.competition.toAdd.push(data);
                        row.classList.add('pending-row');
                        row.querySelectorAll('input, select').forEach(input => input.disabled = true);
                    }
                });

                document.querySelectorAll('#affiliationTableBody tr[data-new="true"]:not(.pending-row)').forEach(row => {
                    const data = {
                        position: row.querySelector('.affiliation-position')?.value || '',
                        organization: row.querySelector('.affiliation-org')?.value || '',
                        years_active: row.querySelector('.affiliation-year')?.value || ''
                    };
                    if (data.position && data.organization) {
                        pendingChanges.affiliation.toAdd.push(data);
                        row.classList.add('pending-row');
                        row.querySelectorAll('input').forEach(input => input.disabled = true);
                    }
                });
            };

            collectNewRows();
            // Log pending changes for debugging
            console.log('=== SAVING PROFILE ===');
            console.log('Pending Changes:', JSON.stringify(pendingChanges, null, 2));
            
            // Collect updated data
            const updatedData = {
                first_name: document.getElementById('profile_firstName')?.textContent.trim() || '',
                middle_name: document.getElementById('profile_middleName')?.textContent.trim() || '',
                last_name: document.getElementById('profile_lastName')?.textContent.trim() || '',
                date_of_birth: document.getElementById('profile_dateOfBirth')?.textContent.trim() || '',
                age: document.getElementById('profile_age')?.textContent.trim() || '',
                gender: document.getElementById('profile_gender')?.textContent.trim() || '',
                place_of_birth: document.getElementById('profile_placeOfBirth')?.textContent.trim() || '',
                email: document.getElementById('profile_email')?.textContent.trim() || '',
                contact_number: document.getElementById('profile_contactNumber')?.textContent.trim() || '',
                address: document.getElementById('profile_permanentAddress')?.textContent.trim() || '',
                present_address: document.getElementById('profile_presentAddress')?.textContent.trim() || '',
                father_name: document.getElementById('profile_fatherName')?.textContent.trim() || '',
                mother_name: document.getElementById('profile_motherName')?.textContent.trim() || '',
                guardian: document.getElementById('profile_guardian')?.textContent.trim() || '',
                guardian_contact: document.getElementById('profile_guardianContact')?.textContent.trim() || '',
                campus: document.getElementById('profile_campus')?.textContent.trim() || '',
                college: document.getElementById('profile_college')?.textContent.trim() || '',
                sr_code: document.getElementById('profile_srCode')?.textContent.trim() || '',
                year_level: document.getElementById('profile_yearLevel')?.textContent.trim() || '',
                program: document.getElementById('profile_program')?.textContent.trim() || '',
                first_semester_units: document.getElementById('profile_firstSemesterUnits')?.textContent.trim() || '0',
                second_semester_units: document.getElementById('profile_secondSemesterUnits')?.textContent.trim() || '0',
                instructors: document.getElementById('profile_instructors')?.textContent.trim() || '',
                // Add pending changes
                pendingChanges: pendingChanges
            };
            
            // Validate required fields
            const validationErrors = [];
            
            // Name validation (no numbers or special characters except . ' -)
            const nameRegex = /^[a-zA-ZÑñ\s.'\-]+$/;
            const nameFields = [
                { value: updatedData.first_name, label: 'First Name' },
                { value: updatedData.last_name, label: 'Last Name' },
                { value: updatedData.father_name, label: "Father's Name" },
                { value: updatedData.mother_name, label: "Mother's Name" }
            ];
            
            nameFields.forEach(field => {
                if (field.value && !nameRegex.test(field.value)) {
                    validationErrors.push(`${field.label} should only contain letters, spaces, and common punctuation (. ' -)`);
                }
                if (field.value && /\d/.test(field.value)) {
                    validationErrors.push(`${field.label} cannot contain numbers`);
                }
            });
            
            // Middle name is optional but if provided, must be valid
            if (updatedData.middle_name && !nameRegex.test(updatedData.middle_name)) {
                validationErrors.push("Middle Name should only contain letters, spaces, and common punctuation (. ' -)");
            }
            
            // Required field validation
            if (!updatedData.first_name) validationErrors.push('First Name is required');
            if (!updatedData.last_name) validationErrors.push('Last Name is required');
            if (!updatedData.email) validationErrors.push('Email is required');
            if (!updatedData.campus) validationErrors.push('Campus is required');
            if (!updatedData.sr_code) validationErrors.push('SR Code is required');
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (updatedData.email && !emailRegex.test(updatedData.email)) {
                validationErrors.push('Please enter a valid email address');
            }
            
            // SR Code format validation (XX-XXXXX)
            const srCodeRegex = /^\d{2}-\d{5}$/;
            if (updatedData.sr_code && !srCodeRegex.test(updatedData.sr_code)) {
                validationErrors.push('SR Code format should be XX-XXXXX (e.g., 22-12345)');
            }
            
            // Contact number validation (11 digits, starting with 09)
            if (updatedData.contact_number) {
                const digits = updatedData.contact_number.replace(/\D/g, '');
                if (digits && (digits.length !== 11 || !digits.startsWith('09'))) {
                    validationErrors.push('Contact number should be 11 digits starting with 09');
                }
            }
            
            // Age validation
            if (updatedData.age) {
                const age = parseInt(updatedData.age);
                if (isNaN(age) || age < 1 || age > 150) {
                    validationErrors.push('Please enter a valid age');
                }
            }
            
            // Units validation (must be numbers)
            if (updatedData.first_semester_units && isNaN(parseInt(updatedData.first_semester_units))) {
                validationErrors.push('First Semester Units must be a number');
            }
            if (updatedData.second_semester_units && isNaN(parseInt(updatedData.second_semester_units))) {
                validationErrors.push('Second Semester Units must be a number');
            }
            
            // Show validation errors
            if (validationErrors.length > 0) {
                closeSaveModal();
                alert('⚠️ Please fix the following errors:\n\n' + validationErrors.join('\n'));
                return;
            }
            
            console.log('Data to send:', JSON.stringify(updatedData, null, 2));

            // Close modal and show loading
            closeSaveModal();
            const saveBtn = document.getElementById('saveProfileBtn');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = 'Saving All Changes...';
            saveBtn.disabled = true;

            // Process photo changes first if any
            let photoPromise = Promise.resolve();
            
            if (pendingChanges.photo.action === 'upload' && pendingChanges.photo.file) {
                console.log('🔄 Uploading photo to server...');
                console.log('Photo file:', pendingChanges.photo.file.name, pendingChanges.photo.file.size, 'bytes');
                
                const formData = new FormData();
                formData.append('profile_photo', pendingChanges.photo.file);
                
                photoPromise = fetch('upload_profile_photo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Photo upload response status:', response.status);
                    return response.json();
                })
                .then(photoData => {
                    console.log('Photo upload result:', photoData);
                    return photoData;
                });
            } else if (pendingChanges.photo.action === 'delete') {
                console.log('🗑️ Deleting photo from server...');
                photoPromise = fetch('delete_profile_photo.php', {
                    method: 'POST'
                }).then(response => response.json());
            }
            
            // Wait for photo operation to complete, then save profile
            photoPromise.then(photoResult => {
                if (photoResult && photoResult.success === false) {
                    throw new Error(photoResult.message || 'Photo operation failed');
                }
                
                console.log('Photo operation completed:', photoResult);
                
                // Update photo display immediately based on operation
                const photoContainer = document.getElementById('profilePhotoContainer');
                if (photoContainer) {
                    if (photoResult && photoResult.photo_url) {
                        // Photo uploaded - show new image
                        photoContainer.innerHTML = `<img src="../${photoResult.photo_url}?v=${Date.now()}" alt="Profile Photo" id="profilePhotoImg">`;
                    } else if (pendingChanges.photo.action === 'delete') {
                        // Photo deleted - show placeholder
                        photoContainer.innerHTML = '<span id="profilePhotoPlaceholder">Passport Size Photo</span>';
                    }
                }
                
                console.log('Sending profile update to server...');
                
                // Send update request
                return fetch('update_profile.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(updatedData)
                });
            })
            .then(response => {
                console.log('Server response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Server response data:', data);
                
                if (data.success) {
                    // Clear pending changes first
                    pendingChanges = {
                        participation: { toAdd: [], toDelete: [] },
                        competition: { toAdd: [], toDelete: [] },
                        affiliation: { toAdd: [], toDelete: [] },
                        photo: { action: null, file: null, preview: null }
                    };
                    
                    isEditMode = false;
                    // Reset buttons/controls and re-fetch profile data
                    const editBtn = document.getElementById('editProfileBtn');
                    const saveBtn = document.getElementById('saveProfileBtn');
                    const cancelBtn = document.getElementById('cancelEditBtn');
                    if (editBtn) editBtn.style.display = 'inline-block';
                    if (saveBtn) saveBtn.style.display = 'none';
                    if (cancelBtn) cancelBtn.style.display = 'none';
                    document.querySelectorAll('.edit-column').forEach(el => {
                        el.style.display = 'none';
                    });
                    document.getElementById('participationControls').classList.remove('active');
                    document.getElementById('competitionControls').classList.remove('active');
                    document.getElementById('affiliationControls').classList.remove('active');
                    const instructorsNote = document.getElementById('instructors_note');
                    if (instructorsNote) instructorsNote.style.display = 'none';

                    loadPerformerProfile();
                } else {
                    console.error('Save failed:', data);
                    alert('❌ Error updating profile: ' + (data.message || 'Unknown error'));
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                    saveBtn.textContent = originalText;
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error during save:', error);
                alert('❌ Error updating profile: ' + error.message);
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const passwordModal = document.getElementById('passwordVerificationModal');
            const saveModal = document.getElementById('saveConfirmationModal');
            
            if (event.target == passwordModal) {
                closePasswordModal();
            }
            if (event.target == saveModal) {
                closeSaveModal();
            }
        }

        // Handle Enter key in password field
        document.addEventListener('DOMContentLoaded', function() {
            const lastSaveResponse = sessionStorage.getItem('lastProfileSaveResponse');
            if (lastSaveResponse) {
                console.log('Last profile save response:', JSON.parse(lastSaveResponse));
                sessionStorage.removeItem('lastProfileSaveResponse');
            }

            const passwordInput = document.getElementById('verifyPassword');
            if (passwordInput) {
                passwordInput.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        verifyPassword();
                    }
                });
            }

            // Photo upload event listener is now attached in displayPerformerProfile() after HTML renders
        });

        // Participation Functions
        function addParticipationRow() {
            const tbody = document.getElementById('participationTableBody');
            const newRow = document.createElement('tr');
            newRow.classList.add('edit-table-row');
            newRow.setAttribute('data-new', 'true');
            
            newRow.innerHTML = `
                <td><input type="date" class="participation-date" placeholder="Date"></td>
                <td><input type="text" class="participation-event" placeholder="Event Name"></td>
                <td>
                    <select class="participation-level">
                        <option value="">Select Level</option>
                        <option value="School">School</option>
                        <option value="Municipal">Municipal</option>
                        <option value="Provincial">Provincial</option>
                        <option value="Regional">Regional</option>
                        <option value="National">National</option>
                        <option value="International">International</option>
                    </select>
                </td>
                <td><input type="text" class="participation-rank" placeholder="Rank/Award"></td>
                <td class="edit-column">
                    <button class="delete-row-btn" onclick="removeNewRow(this)">Delete</button>
                </td>
            `;
            const actionCell = newRow.querySelector('.edit-column');
            if (actionCell) {
                actionCell.style.display = 'table-cell';
            }
            tbody.appendChild(newRow);
        }

        function saveParticipationRow(btn) {
            const row = btn.closest('tr');
            const data = {
                date: row.querySelector('.participation-date').value,
                event_name: row.querySelector('.participation-event').value,
                level: row.querySelector('.participation-level').value,
                rank_award: row.querySelector('.participation-rank').value || ''
            };

            if (!data.date || !data.event_name) {
                alert('Please fill in Date and Event Name');
                return;
            }

            // Add to pending changes (not saved to database yet)
            pendingChanges.participation.toAdd.push(data);
            console.log('✅ Participation staged for add:', data);
            console.log('Total pending participation adds:', pendingChanges.participation.toAdd.length);
            
            // Mark row as pending and disable inputs
            row.classList.add('pending-row');
            row.querySelectorAll('input, select').forEach(input => input.disabled = true);
            
            // Change buttons
            btn.textContent = 'Added (Pending)';
            btn.disabled = true;
            btn.style.background = '#ffc107';
            const cancelBtn = row.querySelector('.delete-row-btn');
            cancelBtn.textContent = 'Remove';
            cancelBtn.onclick = function() { removePendingParticipation(row, pendingChanges.participation.toAdd.length - 1); };
            
            alert('✅ Participation record will be added when you click Save Profile');
        }

        function deleteParticipationRow(id) {
            // Stage for deletion (not deleted from database yet)
            const row = document.querySelector(`tr[data-participation-id="${id}"]`);
            if (!row) return;
            
            // Add to pending deletes
            pendingChanges.participation.toDelete.push(id);
            console.log('✅ Participation staged for deletion, ID:', id);
            console.log('Total pending participation deletes:', pendingChanges.participation.toDelete.length);
            
            // Mark row as pending deletion
            row.classList.add('pending-delete');
            row.style.background = '#ffebee';
            row.style.textDecoration = 'line-through';
            row.style.opacity = '0.6';
            
            // Change delete button
            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Undo';
            deleteBtn.onclick = function() { undoDeleteParticipation(id, row); };
            deleteBtn.style.background = '#2196F3';
            
            alert('⚠️ Record marked for deletion. Click Save Profile to confirm.');
        }
        
        function undoDeleteParticipation(id, row) {
            // Remove from pending deletes
            const index = pendingChanges.participation.toDelete.indexOf(id);
            if (index > -1) {
                pendingChanges.participation.toDelete.splice(index, 1);
            }
            
            // Restore row appearance
            row.classList.remove('pending-delete');
            row.style.background = '';
            row.style.textDecoration = '';
            row.style.opacity = '';
            
            // Restore delete button
            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Delete';
            deleteBtn.onclick = function() { deleteParticipationRow(id); };
            deleteBtn.style.background = '';
        }
        
        function removePendingParticipation(row, index) {
            // Remove from pending adds
            pendingChanges.participation.toAdd.splice(index, 1);
            row.remove();
        }

        // Competition Functions
        function addCompetitionRow() {
            const tbody = document.getElementById('competitionTableBody');
            const newRow = document.createElement('tr');
            newRow.classList.add('edit-table-row');
            newRow.setAttribute('data-new', 'true');

            newRow.innerHTML = `
                <td><input type="date" class="competition-date" placeholder="Date"></td>
                <td><input type="text" class="competition-event" placeholder="Event Name"></td>
                <td>
                    <select class="competition-level">
                        <option value="">Select Level</option>
                        <option value="School">School</option>
                        <option value="Municipal">Municipal</option>
                        <option value="Provincial">Provincial</option>
                        <option value="Regional">Regional</option>
                        <option value="National">National</option>
                        <option value="International">International</option>
                    </select>
                </td>
                <td><input type="text" class="competition-rank" placeholder="Rank/Award"></td>
                <td class="edit-column">
                    <button class="delete-row-btn" onclick="removeNewRow(this)">Delete</button>
                </td>
            `;
            const actionCell = newRow.querySelector('.edit-column');
            if (actionCell) {
                actionCell.style.display = 'table-cell';
            }
            tbody.appendChild(newRow);
        }

        function saveCompetitionRow(btn) {
            const row = btn.closest('tr');
            const data = {
                date: row.querySelector('.competition-date').value,
                event_name: row.querySelector('.competition-event').value,
                level: row.querySelector('.competition-level').value,
                rank_award: row.querySelector('.competition-rank').value || ''
            };

            if (!data.date || !data.event_name) {
                alert('Please fill in Date and Event Name');
                return;
            }

            pendingChanges.competition.toAdd.push(data);

            row.classList.add('pending-row');
            row.querySelectorAll('input, select').forEach(input => input.disabled = true);

            btn.textContent = 'Added (Pending)';
            btn.disabled = true;
            btn.style.background = '#ffc107';
            const cancelBtn = row.querySelector('.delete-row-btn');
            cancelBtn.textContent = 'Remove';
            cancelBtn.onclick = function() { removePendingCompetition(row, pendingChanges.competition.toAdd.length - 1); };

            alert('✅ Competition record will be added when you click Save Profile');
        }

        function deleteCompetitionRow(id) {
            const row = document.querySelector(`tr[data-competition-id="${id}"]`);
            if (!row) return;

            pendingChanges.competition.toDelete.push(id);

            row.classList.add('pending-delete');
            row.style.background = '#ffebee';
            row.style.textDecoration = 'line-through';
            row.style.opacity = '0.6';

            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Undo';
            deleteBtn.onclick = function() { undoDeleteCompetition(id, row); };
            deleteBtn.style.background = '#2196F3';

            alert('⚠️ Record marked for deletion. Click Save Profile to confirm.');
        }

        function undoDeleteCompetition(id, row) {
            const index = pendingChanges.competition.toDelete.indexOf(id);
            if (index > -1) {
                pendingChanges.competition.toDelete.splice(index, 1);
            }

            row.classList.remove('pending-delete');
            row.style.background = '';
            row.style.textDecoration = '';
            row.style.opacity = '';

            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Delete';
            deleteBtn.onclick = function() { deleteCompetitionRow(id); };
            deleteBtn.style.background = '';
        }

        function removePendingCompetition(row, index) {
            pendingChanges.competition.toAdd.splice(index, 1);
            row.remove();
        }

        // Affiliation Functions
        function addAffiliationRow() {
            const tbody = document.getElementById('affiliationTableBody');
            const newRow = document.createElement('tr');
            newRow.classList.add('edit-table-row');
            newRow.setAttribute('data-new', 'true');
            
            newRow.innerHTML = `
                <td><input type="text" class="affiliation-position" placeholder="Position"></td>
                <td><input type="text" class="affiliation-org" placeholder="Organization Name"></td>
                <td><input type="text" class="affiliation-year" placeholder="Year"></td>
                <td class="edit-column">
                    <button class="delete-row-btn" onclick="removeNewRow(this)">Delete</button>
                </td>
            `;
            const actionCell = newRow.querySelector('.edit-column');
            if (actionCell) {
                actionCell.style.display = 'table-cell';
            }
            tbody.appendChild(newRow);
        }

        function saveAffiliationRow(btn) {
            const row = btn.closest('tr');
            const data = {
                position: row.querySelector('.affiliation-position').value,
                organization: row.querySelector('.affiliation-org').value,
                years_active: row.querySelector('.affiliation-year').value
            };

            if (!data.position || !data.organization) {
                alert('Please fill in Position and Organization Name');
                return;
            }

            // Add to pending changes (not saved to database yet)
            pendingChanges.affiliation.toAdd.push(data);
            
            // Mark row as pending and disable inputs
            row.classList.add('pending-row');
            row.querySelectorAll('input').forEach(input => input.disabled = true);
            
            // Change buttons
            btn.textContent = 'Added (Pending)';
            btn.disabled = true;
            btn.style.background = '#ffc107';
            const cancelBtn = row.querySelector('.delete-row-btn');
            cancelBtn.textContent = 'Remove';
            cancelBtn.onclick = function() { removePendingAffiliation(row, pendingChanges.affiliation.toAdd.length - 1); };
            
            alert('✅ Affiliation record will be added when you click Save Profile');
        }

        function deleteAffiliationRow(id) {
            // Stage for deletion (not deleted from database yet)
            const row = document.querySelector(`tr[data-affiliation-id="${id}"]`);
            if (!row) return;
            
            // Add to pending deletes
            pendingChanges.affiliation.toDelete.push(id);
            
            // Mark row as pending deletion
            row.classList.add('pending-delete');
            row.style.background = '#ffebee';
            row.style.textDecoration = 'line-through';
            row.style.opacity = '0.6';
            
            // Change delete button
            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Undo';
            deleteBtn.onclick = function() { undoDeleteAffiliation(id, row); };
            deleteBtn.style.background = '#2196F3';
            
            alert('⚠️ Record marked for deletion. Click Save Profile to confirm.');
        }
        
        function undoDeleteAffiliation(id, row) {
            // Remove from pending deletes
            const index = pendingChanges.affiliation.toDelete.indexOf(id);
            if (index > -1) {
                pendingChanges.affiliation.toDelete.splice(index, 1);
            }
            
            // Restore row appearance
            row.classList.remove('pending-delete');
            row.style.background = '';
            row.style.textDecoration = '';
            row.style.opacity = '';
            
            // Restore delete button
            const deleteBtn = row.querySelector('.delete-row-btn');
            deleteBtn.textContent = 'Delete';
            deleteBtn.onclick = function() { deleteAffiliationRow(id); };
            deleteBtn.style.background = '';
        }
        
        function removePendingAffiliation(row, index) {
            // Remove from pending adds
            pendingChanges.affiliation.toAdd.splice(index, 1);
            row.remove();
        }

        function removeNewRow(btn) {
            btn.closest('tr').remove();
        }

        // Photo Functions
        function uploadProfilePhoto(file) {
            console.log('📸 uploadProfilePhoto called with file:', file);
            
            // Only allow photo upload in edit mode
            if (!isEditMode) {
                alert('⚠️ Please click "Edit Profile" first to upload a photo.');
                console.log('❌ Not in edit mode');
                return;
            }
            
            if (!file) {
                alert('Please select a file');
                console.log('❌ No file selected');
                return;
            }

            console.log('File details:', {
                name: file.name,
                type: file.type,
                size: file.size
            });

            if (!file.type.match('image/jpeg') && !file.type.match('image/jpg') && !file.type.match('image/png')) {
                alert('Only JPG, JPEG, and PNG files are allowed');
                console.log('❌ Invalid file type:', file.type);
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                console.log('❌ File too large:', file.size);
                return;
            }

            console.log('✅ File validation passed, reading file...');

            // Stage the photo upload - don't save yet
            const reader = new FileReader();
            reader.onload = function(e) {
                console.log('✅ File read successfully, staging photo...');
                
                // Store the file and preview in pending changes
                pendingChanges.photo.action = 'upload';
                pendingChanges.photo.file = file;
                pendingChanges.photo.preview = e.target.result;
                
                console.log('Pending changes after staging:', pendingChanges);
                
                // Update the photo preview immediately in the container
                const photoContainer = document.getElementById('profilePhotoContainer');
                if (photoContainer) {
                    photoContainer.innerHTML = `<img src="${e.target.result}" alt="Profile Photo" id="profilePhotoImg">`;
                    console.log('✅ Photo preview updated in container');
                } else {
                    console.log('❌ Photo container not found!');
                }
                
                // Update button to show pending status
                const uploadBtn = document.querySelector('.photo-upload-btn');
                if (uploadBtn) {
                    uploadBtn.textContent = '📷 Photo Selected (Pending)';
                    uploadBtn.style.backgroundColor = '#FFC107';
                    console.log('✅ Upload button updated');
                } else {
                    console.log('❌ Upload button not found!');
                }
                
                // Show message that photo will be saved when Save Profile is clicked
                alert('📸 Photo selected! Click "Save Profile" to upload.');
            };
            reader.readAsDataURL(file);
            
            // Clear file input
            document.getElementById('photoUploadInput').value = '';
        }

        function deleteProfilePhoto() {
            console.log('🗑️ deleteProfilePhoto called');
            console.log('Current photo in pending changes:', pendingChanges.photo);
            
            // Only allow photo deletion in edit mode
            if (!isEditMode) {
                alert('⚠️ Please click "Edit Profile" first to delete a photo.');
                console.log('❌ Not in edit mode');
                return;
            }
            
            // Check if there's actually a photo to delete
            const photoContainer = document.getElementById('profilePhotoContainer');
            const hasPhoto = photoContainer && photoContainer.querySelector('img');
            
            if (!hasPhoto && !pendingChanges.photo.file) {
                alert('⚠️ No photo to delete.');
                console.log('❌ No photo exists to delete');
                return;
            }
            
            if (!confirm('Are you sure you want to mark your profile photo for deletion?')) {
                console.log('❌ User cancelled deletion');
                return;
            }

            console.log('✅ Staging photo deletion...');

            // Stage the photo deletion - don't delete yet
            pendingChanges.photo.action = 'delete';
            pendingChanges.photo.file = null;
            pendingChanges.photo.preview = null;
            
            // Update the photo preview to show placeholder (reuse photoContainer from above)
            if (photoContainer) {
                photoContainer.innerHTML = '<span id="profilePhotoPlaceholder">Passport Size Photo</span>';
            }
            
            // Update button to show pending status
            const deleteBtn = document.querySelector('.photo-delete-btn');
            if (deleteBtn) {
                deleteBtn.textContent = '🗑️ Marked for Deletion (Pending)';
                deleteBtn.style.backgroundColor = '#f44336';
                deleteBtn.style.color = 'white';
            }
            
            // Show message that deletion will happen when Save Profile is clicked
            alert('🗑️ Photo marked for deletion! Click "Save Profile" to confirm.');
        }
    </script>
    <?php $chatbotBasePath = '../'; include __DIR__ . '/../components/oca-chatbot.php'; ?>
</body>
</html>
