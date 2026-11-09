<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $student_id = intval($input['student_id'] ?? 0);
    
    if (empty($student_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
        exit();
    }
    
    $pdo = getDBConnection();
    
    // Get student profile from student_artists table
    $stmt = $pdo->prepare("
        SELECT * FROM student_artists 
        WHERE id = ? AND status IN ('active', 'suspended')
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit();
    }
    
    // Get the performance_type from applications table using sr_code (what they applied for)
    $appStmt = $pdo->prepare("
        SELECT performance_type FROM applications 
        WHERE sr_code = ? 
        LIMIT 1
    ");
    $appStmt->execute([$student['sr_code']]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    
    // Extract the performance type value from JSON if needed
    $performanceType = null;
    if ($application && $application['performance_type']) {
        $perfData = json_decode($application['performance_type'], true);
        if (is_array($perfData)) {
            // Get all values from the array and join them
            $values = array_values(array_filter($perfData));
            $performanceType = end($values); // Get the last value which is usually the actual performance type
        } else {
            // If not JSON, try to extract value after colon
            if (strpos($application['performance_type'], ':') !== false) {
                $parts = explode(':', $application['performance_type']);
                $performanceType = trim(end($parts));
            } else {
                $performanceType = $application['performance_type'];
            }
        }
    }
    $student['performance_type'] = $performanceType;
    
    // Get participation records
    $stmt = $pdo->prepare("
        SELECT participation_date as date, event_name as title, 
               participation_level as level, rank_award as rank
        FROM student_participation_records
        WHERE student_id = ?
        ORDER BY participation_date DESC
    ");
    $stmt->execute([$student_id]);
    $participation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get competition records (NEW - Section IV)
    $stmt = $pdo->prepare("
        SELECT competition_date as date, event_name as title, 
               competition_level as level, rank_award as rank
        FROM student_competition_records
        WHERE student_id = ?
        ORDER BY competition_date DESC
    ");
    $stmt->execute([$student_id]);
    $competition = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get affiliation records
    $stmt = $pdo->prepare("
        SELECT position, organization, years_active as year
        FROM student_affiliation_records
        WHERE student_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$student_id]);
    $affiliation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add participation, competition, and affiliation to student data
    $student['participation'] = $participation;
    $student['competition'] = $competition;
    $student['affiliation'] = $affiliation;
    
    // Store the student_id for reference
    $student['student_id'] = $student_id;

    unset($student['password']);
    
    // profile_photo is already in the student_artists data, no need to fetch from applications
    
    echo json_encode([
        'success' => true, 
        'student' => $student
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error fetching student profile: ' . $e->getMessage()
    ]);
}
?>