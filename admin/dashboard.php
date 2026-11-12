<?php
session_start();
require_once '../config/database.php';
require_once '../services/NotificationService.php';

// Handle AJAX requests FIRST - before any other processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Prevent any output before JSON response
    ob_start();
    ob_clean();
    
    // Disable error display to prevent HTML in JSON response
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    header('Content-Type: application/json');
    
    // Log the request for debugging
    error_log("AJAX Request - Action: " . ($_POST['action'] ?? 'none'));
    
    $pdo = getDBConnection();
    
    switch ($_POST['action']) {
        case 'add_user':
            try {
                // Ensure clean output buffer
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                $first_name = trim($_POST['first_name']);
                $middle_name = !empty($_POST['middle_name']) ? trim($_POST['middle_name']) : null;
                $last_name = trim($_POST['last_name']);
                $email = trim($_POST['email']);
                $role = $_POST['role'];
                $raw_password = $_POST['password'];
                $sr_code = !empty($_POST['sr_code']) ? trim($_POST['sr_code']) : null;
                $campus = !empty($_POST['campus']) ? trim($_POST['campus']) : null;
                
                // Basic password validation (admin can set any password format)
                if (empty($raw_password)) {
                    echo json_encode(['success' => false, 'message' => 'Password is required']);
                    exit();
                }
                
                $password = password_hash($raw_password, PASSWORD_DEFAULT);
                
                // If role is student, SR code is required
                if ($role === 'student' && empty($sr_code)) {
                    echo json_encode(['success' => false, 'message' => 'SR Code is required for student accounts']);
                    exit();
                }
                
                // Insert into appropriate table based on role
                if ($role === 'student') {
                    // Debug: Log the attempt
                    error_log("Attempting to insert student: SR=$sr_code, Email=$email");
                    
                    // For students, insert into student_artists table with comprehensive data
                    $stmt = $pdo->prepare("INSERT INTO student_artists (sr_code, first_name, middle_name, last_name, email, password, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                    $result = $stmt->execute([$sr_code, $first_name, $middle_name, $last_name, $email, $password]);
                    
                    if (!$result) {
                        $errorInfo = $stmt->errorInfo();
                        error_log("SQL Error: " . print_r($errorInfo, true));
                        throw new Exception("Failed to insert into student_artists table. SQL Error: " . $errorInfo[2]);
                    }
                } else {
                    // For other roles, insert into users table (with campus)
                    // Directors don't need a specific campus - they have access to all
                    $campus_value = ($role === 'director') ? null : $campus;
                    $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, campus) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt->execute([$first_name, $middle_name, $last_name, $email, $password, $role, $campus_value])) {
                        throw new Exception("Failed to insert into users table: " . implode(", ", $stmt->errorInfo()));
                    }
                }
                
                $user_id = $pdo->lastInsertId();
                
                // Log admin action
                try {
                    logAdminAction($pdo, $_SESSION['admin_id'], 'USER_ADD', $user_id, "Added new " . ($role === 'student' ? 'student artist' : 'user') . ": $email" . ($sr_code ? " (SR: $sr_code)" : ""));
                } catch (Exception $logError) {
                    // Log error but don't fail the user creation
                    error_log("Failed to log admin action: " . $logError->getMessage());
                }
                
                echo json_encode(['success' => true, 'message' => 'User added successfully']);
                exit();
            } catch (Exception $e) {
                // Ensure clean output
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                error_log("Add user error: " . $e->getMessage());
                
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    if (strpos($e->getMessage(), 'sr_code') !== false) {
                        echo json_encode(['success' => false, 'message' => 'SR Code already exists']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error adding user: ' . $e->getMessage()]);
                }
                exit();
            }
            
        case 'archive_user':
            try {
                // Ensure clean output
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                $user_id = intval($_POST['user_id']);
                $source_table = $_POST['source_table'] ?? 'users';
                
                if ($source_table === 'student_artists') {
                    // Update status to inactive for student artists (used as archive state)
                    $stmt = $pdo->prepare("UPDATE student_artists SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Log the action
                    try {
                        logAdminAction($pdo, $_SESSION['admin_id'] ?? 1, 'USER_ARCHIVE', $user_id, "Student artist archived (set inactive)");
                    } catch (Exception $logError) {
                        // Ignore logging errors to prevent JSON corruption
                    }
                } else {
                    // Update status to inactive for regular users (used as archive state)
                    $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    try {
                        logAdminAction($pdo, $_SESSION['admin_id'] ?? 1, 'USER_ARCHIVE', $user_id, "User archived (set inactive) from $source_table");
                    } catch (Exception $logError) {
                        // Ignore logging errors
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'User archived successfully']);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error archiving user: ' . $e->getMessage()]);
            }
            exit();
            
        case 'permanent_delete_user':
            try {
                // Ensure clean output
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                $user_id = intval($_POST['user_id']);
                $source_table = $_POST['source_table'] ?? 'users';
                
                if ($source_table === 'student_artists') {
                    // First, get the student's details before deletion
                    $stmt = $pdo->prepare("SELECT sr_code, email, first_name, last_name FROM student_artists WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($student) {
                        // Add to deleted_students tracking table
                        $stmt = $pdo->prepare("INSERT INTO deleted_students (sr_code, email, deleted_by, reason) VALUES (?, ?, ?, ?)");
                        $reason = "Student account permanently deleted by admin from archive";
                        $stmt->execute([$student['sr_code'], $student['email'], $_SESSION['admin_id'] ?? 1, $reason]);
                        
                        // Now delete from student_artists
                        $stmt = $pdo->prepare("DELETE FROM student_artists WHERE id = ?");
                        $stmt->execute([$user_id]);
                        
                        // Log the action
                        try {
                            logAdminAction($pdo, $_SESSION['admin_id'] ?? 1, 'USER_PERMANENT_DELETE', $user_id, "Student artist permanently deleted: {$student['first_name']} {$student['last_name']} ({$student['sr_code']})");
                        } catch (Exception $logError) {
                            // Ignore logging errors to prevent JSON corruption
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    try {
                        logAdminAction($pdo, $_SESSION['admin_id'] ?? 1, 'USER_PERMANENT_DELETE', $user_id, "User permanently deleted from $source_table");
                    } catch (Exception $logError) {
                        // Ignore logging errors
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'User permanently deleted successfully']);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error permanently deleting user: ' . $e->getMessage()]);
            }
            exit();
            
        case 'suspend_user':
            try {
                $user_id = intval($_POST['user_id']);
                $source_table = $_POST['source_table'] ?? 'users';

                if (!in_array($source_table, ['users', 'student_artists'], true)) {
                    throw new Exception('Invalid account source');
                }
                $stmt = $pdo->prepare("UPDATE \"$source_table\" SET status = 'suspended' WHERE id = ? AND status = 'active'");
                $stmt->execute([$user_id]);

                if ($stmt->rowCount() === 1) {
                    NotificationService::sendAccountSuspension($pdo, $source_table, $user_id, $_POST['reason'] ?? null);
                }
                
                logAdminAction($pdo, $_SESSION['admin_id'], 'USER_SUSPEND', $user_id, "User suspended in $source_table");
                echo json_encode(['success' => true, 'message' => 'User suspended successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error suspending user']);
            }
            exit();
            
        case 'activate_user':
            try {
                $user_id = intval($_POST['user_id']);
                $source_table = $_POST['source_table'] ?? 'users';

                if (!in_array($source_table, ['users', 'student_artists'], true)) {
                    throw new Exception('Invalid account source');
                }
                $stmt = $pdo->prepare("UPDATE \"$source_table\" SET status = 'active' WHERE id = ? AND status <> 'active'");
                $stmt->execute([$user_id]);

                if ($stmt->rowCount() === 1) {
                    NotificationService::sendAccountActivation($pdo, $source_table, $user_id);
                }
                
                logAdminAction($pdo, $_SESSION['admin_id'], 'USER_ACTIVATE', $user_id, "User activated in $source_table");
                echo json_encode(['success' => true, 'message' => 'User activated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error activating user']);
            }
            exit();
            
        case 'update_user':
            try {
                $user_id = intval($_POST['user_id']);
                $source_table = $_POST['source_table'] ?? 'users';
                $first_name = trim($_POST['first_name']);
                $middle_name = !empty($_POST['middle_name']) ? trim($_POST['middle_name']) : null;
                $last_name = trim($_POST['last_name']);
                $email = trim($_POST['email']);
                $role = $_POST['role'] ?? null;
                $campus = !empty($_POST['campus']) ? trim($_POST['campus']) : null;
                
                // Debug logging
                error_log("UPDATE USER - ID: $user_id, Role: $role, Campus: $campus, Source: $source_table");
                
                if ($source_table === 'student_artists') {
                    // Update student_artists table (no role field)
                    if (!empty($_POST['password'])) {
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE student_artists SET first_name = ?, middle_name = ?, last_name = ?, email = ?, password = ? WHERE id = ?");
                        $stmt->execute([$first_name, $middle_name, $last_name, $email, $password, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE student_artists SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$first_name, $middle_name, $last_name, $email, $user_id]);
                    }
                } else {
                    // Update users table (has role and campus fields)
                    // Directors don't need a specific campus - they have access to all
                    $campus_value = ($role === 'director') ? null : $campus;
                    
                    error_log("=== UPDATE USER DEBUG ===");
                    error_log("User ID: $user_id (type: " . gettype($user_id) . ")");
                    error_log("Role: '$role' (type: " . gettype($role) . ", length: " . strlen($role) . ")");
                    error_log("Campus value: '" . ($campus_value === null ? 'NULL' : $campus_value) . "'");
                    
                    if (!empty($_POST['password'])) {
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $sql = "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, role = ?, campus = ?, password = ? WHERE id = ?";
                        $params = [$first_name, $middle_name, $last_name, $email, $role, $campus_value, $password, $user_id];
                        error_log("SQL: $sql");
                        error_log("Params: " . json_encode($params));
                        $stmt = $pdo->prepare($sql);
                        $result = $stmt->execute($params);
                        error_log("Result: " . ($result ? 'SUCCESS' : 'FAILED') . ", Rows affected: " . $stmt->rowCount());
                    } else {
                        $sql = "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, role = ?, campus = ? WHERE id = ?";
                        $params = [$first_name, $middle_name, $last_name, $email, $role, $campus_value, $user_id];
                        error_log("SQL: $sql");
                        error_log("Params: " . json_encode($params));
                        $stmt = $pdo->prepare($sql);
                        $result = $stmt->execute($params);
                        error_log("Result: " . ($result ? 'SUCCESS' : 'FAILED') . ", Rows affected: " . $stmt->rowCount());
                        if ($stmt->rowCount() === 0) {
                            error_log("ERROR: No rows were updated!");
                            $errorInfo = $stmt->errorInfo();
                            error_log("PDO Error Info: " . json_encode($errorInfo));
                        }
                    }
                    
                    // Verify the update
                    $verify = $pdo->prepare("SELECT id, role, campus FROM users WHERE id = ?");
                    $verify->execute([$user_id]);
                    $updated = $verify->fetch(PDO::FETCH_ASSOC);
                    error_log("After UPDATE - DB record: " . json_encode($updated));
                    
                    // Try a direct UPDATE as a test
                    error_log("Attempting direct UPDATE query...");
                    $directUpdate = $pdo->exec("UPDATE users SET role = 'director' WHERE id = 3");
                    error_log("Direct UPDATE affected rows: " . $directUpdate);
                    
                    // Check again
                    $verify2 = $pdo->prepare("SELECT id, role, campus FROM users WHERE id = ?");
                    $verify2->execute([$user_id]);
                    $updated2 = $verify2->fetch(PDO::FETCH_ASSOC);
                    error_log("After direct UPDATE - DB record: " . json_encode($updated2));
                    
                    error_log("=== END DEBUG ===");
                }
                
                logAdminAction($pdo, $_SESSION['admin_id'], 'USER_UPDATE', $user_id, "Updated user: $email from table: $source_table");
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()]);
                }
            }
            exit();
            
        case 'get_user':
            try {
                $user_id = intval($_POST['user_id']);
                $source_table = isset($_POST['source_table']) ? $_POST['source_table'] : 'users';
                
                // Validate table name
                if (!in_array($source_table, ['users', 'student_artists'])) {
                    throw new Exception('Invalid table specified');
                }
                
                $stmt = $pdo->prepare("SELECT * FROM \"$source_table\" WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    unset($user['password']); // Don't send password
                    echo json_encode(['success' => true, 'user' => $user]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error fetching user: ' . $e->getMessage()]);
            }
            exit();
            
        case 'allow_readd_student':
            try {
                $sr_code = $_POST['sr_code'] ?? '';
                $email = $_POST['email'] ?? '';
                
                if (empty($sr_code) && empty($email)) {
                    throw new Exception('SR code or email is required');
                }
                
                // Remove from deleted_students table to allow re-adding
                if (!empty($sr_code)) {
                    $stmt = $pdo->prepare("DELETE FROM deleted_students WHERE sr_code = ?");
                    $stmt->execute([$sr_code]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM deleted_students WHERE email = ?");
                    $stmt->execute([$email]);
                }
                
                logAdminAction($pdo, $_SESSION['admin_id'], 'STUDENT_UNDELETE', 0, "Allowed re-addition of student: $sr_code $email");
                echo json_encode(['success' => true, 'message' => 'Student can now be re-added from approved applications']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error allowing student re-addition: ' . $e->getMessage()]);
            }
            exit();
            
        case 'sync_deleted_students':
            try {
                // Find approved applications that don't have corresponding student accounts
                // and aren't already in the deleted_students table - these were likely deleted before tracking
                $stmt = $pdo->prepare("
                    SELECT DISTINCT a.sr_code, a.email 
                    FROM applications a 
                    WHERE a.application_status = 'approved' 
                    AND a.sr_code NOT IN (SELECT sr_code FROM student_artists WHERE sr_code IS NOT NULL)
                    AND a.email NOT IN (SELECT email FROM student_artists WHERE email IS NOT NULL)
                    AND a.sr_code NOT IN (SELECT sr_code FROM deleted_students WHERE sr_code IS NOT NULL)
                    AND a.email NOT IN (SELECT email FROM deleted_students WHERE email IS NOT NULL)
                ");
                $stmt->execute();
                $missingStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $addedCount = 0;
                foreach ($missingStudents as $student) {
                    $stmt = $pdo->prepare("INSERT INTO deleted_students (sr_code, email, deleted_by, reason) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$student['sr_code'], $student['email'], $_SESSION['admin_id'], 'Previously deleted by admin - added during sync']);
                    $addedCount++;
                }
                
                logAdminAction($pdo, $_SESSION['admin_id'], 'SYNC_DELETED', 0, "Synced $addedCount previously deleted students to tracking table");
                echo json_encode(['success' => true, 'message' => "Synced $addedCount previously deleted students to tracking table"]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error syncing deleted students: ' . $e->getMessage()]);
            }
            exit();
            
        case 'update_system_settings':
            try {
                $admin_email = trim($_POST['admin_email'] ?? '');
                $admin_password = $_POST['admin_password'] ?? '';
                
                if (empty($admin_email)) {
                    throw new Exception('Admin email is required');
                }
                
                // Validate email format
                if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                
                // Check if this email already exists in users table with different role
                $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
                $stmt->execute([$admin_email]);
                $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingUser && $existingUser['role'] !== 'admin') {
                    throw new Exception('This email is already registered with a different role');
                }
                
                // Get current admin user
                $stmt = $pdo->prepare("SELECT id, email FROM users WHERE role = 'admin' LIMIT 1");
                $stmt->execute();
                $currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($currentAdmin) {
                    // Update existing admin user
                    if (!empty($admin_password)) {
                        // Update both email and password
                        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET email = ?, password = ? WHERE id = ?");
                        $stmt->execute([$admin_email, $hashed_password, $currentAdmin['id']]);
                    } else {
                        // Update only email
                        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $stmt->execute([$admin_email, $currentAdmin['id']]);
                    }
                } else {
                    // Create new admin user if none exists
                    $default_password = !empty($admin_password) ? password_hash($admin_password, PASSWORD_DEFAULT) : password_hash('batstateu', PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (first_name, last_name, email, password, role, status) 
                        VALUES ('Admin', 'User', ?, ?, 'admin', 'active')
                    ");
                    $stmt->execute([$admin_email, $default_password]);
                }
                
                // Update system settings in a settings table (create if doesn't exist)
                try {
                    // Update or insert admin email
                    $stmt = $pdo->prepare("
                        INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('admin_email', ?) 
                        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
                    ");
                    $stmt->execute([$admin_email]);
                    
                } catch (Exception $settingsError) {
                    // If settings table operations fail, continue anyway since user table was updated
                    error_log("Settings table error: " . $settingsError->getMessage());
                }
                
                // Log admin action
                try {
                    logAdminAction($pdo, $_SESSION['admin_id'] ?? 1, 'SYSTEM_SETTINGS_UPDATE', 0, "Updated system settings - Admin email: $admin_email");
                } catch (Exception $logError) {
                    // Ignore logging errors
                }
                
                echo json_encode(['success' => true, 'message' => 'System settings updated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating system settings: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_system_settings':
            try {
                $settings = ['admin_email' => 'admin@g.batstate-u.edu.ph'];
                
                // Try to get from database
                try {
                    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'admin_email'");
                    $stmt->execute();
                    $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    if (!empty($dbSettings)) {
                        $settings = array_merge($settings, $dbSettings);
                    }
                } catch (Exception $e) {
                    // If settings table doesn't exist, use defaults
                }
                
                // Get admin email from users table if not in settings
                if (!isset($settings['admin_email']) || empty($settings['admin_email'])) {
                    $stmt = $pdo->prepare("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
                    $stmt->execute();
                    $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($adminUser) {
                        $settings['admin_email'] = $adminUser['email'];
                    }
                }
                
                echo json_encode(['success' => true, 'settings' => $settings]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading system settings: ' . $e->getMessage()]);
            }
            exit();
            
        case 'logout':
            try {
                // Clear all session data
                session_unset();
                session_destroy();
                echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Logout error: ' . $e->getMessage()]);
            }
            exit();
            
        // ... other cases will be added here
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $_POST['action']]);
            exit();
    }
}

// Prevent any output before JSON response
ob_start();

// Admin authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header('Location: ../index.php');
    exit();
}

// Set admin_logged_in for backwards compatibility
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = $_SESSION['user_id'];

$pdo = getDBConnection();

// Ensure an admin user exists
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    $adminCount = $stmt->fetchColumn();
    
    if ($adminCount == 0) {
        // Create default admin user
        $defaultPassword = password_hash('batstateu', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, status) 
            VALUES ('Admin', 'User', 'admin@g.batstate-u.edu.ph', ?, 'admin', 'active')
        ");
        $stmt->execute([$defaultPassword]);
        $_SESSION['admin_id'] = $pdo->lastInsertId();
    } else {
        // Get the admin user ID for session
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $stmt->execute();
        $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($adminUser) {
            $_SESSION['admin_id'] = $adminUser['id'];
        }
    }
} catch (Exception $e) {
    // If there's an error, just continue with default admin_id
    error_log("Error ensuring admin user exists: " . $e->getMessage());
}

// Get users from database
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination variables
$users_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$students_page = isset($_GET['students_page']) ? max(1, intval($_GET['students_page'])) : 1;

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all users - separate regular users and student artists
$regular_users = [];
$student_artists = [];

// Get regular users (excluding student role from regular users table)
$regular_where_conditions = [];
$regular_params = [];

if (!empty($search)) {
    $regular_where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $regular_params[] = $search_param;
    $regular_params[] = $search_param;
    $regular_params[] = $search_param;
    $regular_params[] = $search_param;
}

// Exclude student role from regular users table and exclude inactive users (archived)
$regular_where_conditions[] = "role != 'student'";
$regular_where_conditions[] = "status != 'inactive'";

$regular_where_clause = 'WHERE ' . implode(' AND ', $regular_where_conditions);

// Get total count of regular users
$count_sql_users = "SELECT COUNT(*) FROM users $regular_where_clause";
$count_stmt = $pdo->prepare($count_sql_users);
$count_stmt->execute($regular_params);
$total_regular_users = $count_stmt->fetchColumn();

// Calculate pagination for regular users
$total_pages = ceil($total_regular_users / $users_per_page);
$offset = ($current_page - 1) * $users_per_page;

// Get paginated regular users
$sql_users = "SELECT id, first_name, middle_name, last_name, email, role, status, last_login, created_at, 'users' as source_table FROM users $regular_where_clause ORDER BY id ASC LIMIT $users_per_page OFFSET $offset";
$stmt = $pdo->prepare($sql_users);
$stmt->execute($regular_params);
$regular_users = $stmt->fetchAll();

// Get student artists
$student_where_conditions = [];
$student_params = [];

// Exclude inactive student artists (archived)
$student_where_conditions[] = "status != 'inactive'";

if (!empty($search)) {
    $student_where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $student_params[] = $search_param;
    $student_params[] = $search_param;
    $student_params[] = $search_param;
    $student_params[] = $search_param;
}

$student_where_clause = 'WHERE ' . implode(' AND ', $student_where_conditions);

// Get total count of student artists
$count_sql_students = "SELECT COUNT(*) FROM student_artists $student_where_clause";
$count_stmt = $pdo->prepare($count_sql_students);
$count_stmt->execute($student_params);
$total_student_artists = $count_stmt->fetchColumn();

// Calculate pagination for student artists
$total_students_pages = ceil($total_student_artists / $users_per_page);
$students_offset = ($students_page - 1) * $users_per_page;

// Get paginated student artists
$sql_students = "SELECT id, first_name, middle_name, last_name, email, 'student' as role, status, NULL as last_login, created_at, 'student_artists' as source_table FROM student_artists $student_where_clause ORDER BY id ASC LIMIT $users_per_page OFFSET $students_offset";
$stmt = $pdo->prepare($sql_students);
$stmt->execute($student_params);
$student_artists = $stmt->fetchAll();

// For backward compatibility, combine arrays for the main users variable
$all_users = array_merge($regular_users, $student_artists);

if (empty($all_users)) {
    $all_users = $regular_users;
}

$users = $regular_users; // Main table shows only regular users now

// Clean output buffer for HTML rendering
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/jpeg" href="../assets/logo.jpg?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Culture and Arts - BatStateU TNEU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        /* Prevent background scrolling when modals are open */
        body.modal-open {
            overflow: hidden;
        }

        /* Header */
        .header {
            background: white;
            padding: 1rem 2rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 70px;
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
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-btn {
            background: #f8f9fa;
            color: #333;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
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

        /* Main Layout */
        .main-container {
            display: flex;
            min-height: 100vh;
            padding-top: 70px; /* Account for fixed header */
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 2rem 0;
            position: fixed;
            top: 70px;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 999;
        }

        .nav-menu {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: block;
            padding: 1rem 2rem;
            text-decoration: none;
            color: #666;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #f8f9fa;
            color: #e74c3c;
            border-right: 3px solid #e74c3c;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 250px; /* Account for fixed sidebar */
            overflow-y: auto;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        /* User Management */
        /* Tabs Styles */
        .tabs-container {
            background: white;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tabs-nav {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .tab-link {
            flex: 1;
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab-link:hover {
            background: #e9ecef;
            color: #495057;
        }

        .tab-link.active {
            background: white;
            color: #dc3545;
            border-bottom-color: #dc3545;
        }

        .tab-content {
            display: none;
            padding: 1.5rem;
        }

        .tab-content.active {
            display: block;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 600;
            color: #333;
        }

        .add-user-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        /* Search and Filters */
        .search-filters {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .search-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .filter-select {
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 1rem;
        }

        .users-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
        }

        .users-table th {
            background: #f8f9fa;
            padding: 0.875rem 1rem;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 1px solid #e0e0e0;
            white-space: nowrap;
        }

        .users-table th:nth-child(1) {
            width: 6%;
            min-width: 60px;
        }

        .users-table th:nth-child(2) {
            width: 18%;
            min-width: 150px;
        }

        .users-table th:nth-child(3) {
            width: 24%;
            min-width: 200px;
        }

        .users-table th:nth-child(4) {
            width: 12%;
            min-width: 100px;
            text-align: center;
        }

        .users-table th:nth-child(5) {
            width: 12%;
            min-width: 100px;
            text-align: center;
        }

        .users-table th:nth-child(6) {
            width: 28%;
            min-width: 240px;
            text-align: center;
        }

        .users-table td:nth-child(4),
        .users-table td:nth-child(5),
        .users-table td:nth-child(6) {
            text-align: center;
        }

        .users-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #e0e0e0;
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: middle;
        }

        .users-table tbody td:nth-child(1) {
            font-weight: 500;
        }

        .users-table tr:hover {
            background: #f8f9fa;
        }

        /* Role Badges */
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .role-head {
            background: #007bff;
            color: white;
        }

        .role-director {
            background: #ff6b6b;
            color: white;
        }

        .role-student {
            background: #28a745;
            color: white;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Action Buttons */
        .action-btn {
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            margin: 0 0.2rem;
            font-weight: 500;
        }

        .btn-edit {
            background: #007bff;
            color: white;
        }

        .btn-suspend {
            background: #ffc107;
            color: #333;
        }

        .btn-archive {
            background: #6c757d;
            color: white;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-activate {
            background: #28a745;
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }

        .page-btn {
            display: inline-block;
            padding: 0.5rem 0.75rem;
            margin: 0 0.1rem;
            text-decoration: none;
            color: #007bff;
            border: 1px solid #ddd;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .page-btn:hover {
            background-color: #f8f9fa;
            text-decoration: none;
            color: #0056b3;
        }

        .page-btn.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .pagination-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .pagination-info {
            color: #666;
            font-weight: 500;
        }

        /* Settings Form */
        .settings-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .save-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                position: relative;
                top: 0;
                left: 0;
                bottom: auto;
                height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .search-filters {
                flex-direction: column;
                align-items: stretch;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
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
            background-color: rgba(0,0,0,0.5);
            overflow: hidden; /* Prevent background scrolling */
        }

        .modal-content {
            background-color: white;
            margin: 2% auto 8% auto; /* Reduced top margin from 5% to 2%, increased bottom to 8% */
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh; /* Increased from 80vh to 90vh for more height */
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
            overflow-y: auto;
            position: relative;
            /* Hide scrollbar but keep functionality */
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }

        .modal-content::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }

        .modal-header {
            flex-shrink: 0; /* Header stays fixed */
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        #userForm,
        #editUserFormContent {
            padding: 2rem;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.3rem;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            color: #e74c3c;
        }

        #userForm,
        #editUserFormContent {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: #666;
            font-size: 0.85rem;
        }

        .show-password-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: -1.2rem;
            font-size: 0.9rem;
        }

        .show-password-container input[type="checkbox"] {
            cursor: pointer;
            accent-color: #dc2626;
        }

        .show-password-container label {
            cursor: pointer;
            color: #666;
            font-weight: normal;
        }

        .form-row {
            display: flex;
            gap: 1rem;
        }

        .form-row .form-group {
            flex: 1;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Alert styles */
        .alert {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 6px;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Role badges for different roles */
        .role-staff {
            background: #ffc107;
            color: #333;
        }

        /* Status badges for suspended */
        .status-suspended {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/logo.jpg" alt="BatStateU Logo" class="logo">
            <h1 class="header-title">Culture and Arts - Dashboard</h1>
        </div>
        <div class="header-right">
            <button class="admin-btn"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin User') ?></button>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="#" class="nav-link active" data-section="users">User Management</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link" data-section="settings">System Settings</a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- User Management Section -->
            <section class="content-section active" id="users">
                <div class="page-header">
                    <h1 class="page-title">User Management</h1>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <button class="add-user-btn">+ Add New User</button>
                    </div>
                </div>

                <!-- Tabs Container -->
                <div class="tabs-container">
                    <!-- Tabs Navigation -->
                    <div class="tabs-nav">
                        <button class="tab-link active" onclick="switchTab(event, 'staffs-tab')">Staffs</button>
                        <button class="tab-link" onclick="switchTab(event, 'students-tab')">Students</button>
                        <button class="tab-link" onclick="switchTab(event, 'archive-tab')">Archive</button>
                    </div>

                    <!-- Staffs Tab Content -->
                    <div id="staffs-tab" class="tab-content active">
                        <!-- Search and Filters -->
                        <div class="search-filters">
                            <form method="GET" style="display: flex; gap: 1rem; align-items: center; width: 100%;">
                                <input type="hidden" name="tab" value="staffs">
                                <input type="text" name="search" placeholder="Search users..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="search-btn">🔍</button>
                            </form>
                        </div>

                        <!-- System Users Table -->
                        <div class="table-container">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                                                <?php if (!empty($search)): ?>
                                                    No users found matching your criteria.
                                                <?php else: ?>
                                                    No users found. Click "Add New User" to create the first user.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $index => $user): ?>
                                            <tr data-user-id="<?= $user['id'] ?>" data-source-table="<?= $user['source_table'] ?>">
                                                <td><?= $offset + $index + 1 ?></td>
                                                <td><?= htmlspecialchars(trim($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name'])) ?></td>
                                                <td><?= htmlspecialchars($user['email']) ?></td>
                                                <td>
                                                    <?php if (!empty($user['role'])): ?>
                                                        <span class="role-badge role-<?= $user['role'] ?>">
                                                            <?= strtoupper($user['role']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="role-badge" style="background: #dc3545; color: white;">
                                                            NO ROLE
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= $user['status'] ?>">
                                                        <?= strtoupper($user['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-btn btn-edit" onclick="editUser(<?= $user['id'] ?>, '<?= $user['source_table'] ?>')">Edit</button>
                                                    <?php if ($user['status'] === 'active'): ?>
                                                        <button class="action-btn btn-suspend" onclick="suspendUser(<?= $user['id'] ?>, '<?= $user['source_table'] ?>')">Suspend</button>
                                                    <?php else: ?>
                                                        <button class="action-btn btn-activate" onclick="activateUser(<?= $user['id'] ?>, '<?= $user['source_table'] ?>')">Activate</button>
                                                    <?php endif; ?>
                                                    <button class="action-btn btn-archive" onclick="archiveUser(<?= $user['id'] ?>, '<?= $user['source_table'] ?>')">Archive</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Staff Count and Pagination -->
                        <div style="margin-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                            <div style="color: #666;">
                                Total Staff: <?= $total_regular_users ?> (Showing <?= count($users) ?> of <?= $total_regular_users ?>)
                            </div>
                            <?php if ($total_pages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    $query_params = $_GET;
                                    unset($query_params['page']);
                                    $base_url = '?' . http_build_query($query_params);
                                    if (empty($query_params)) $base_url = '?';
                                    ?>
                                    
                                    <?php if ($current_page > 1): ?>
                                        <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>page=<?= $current_page - 1 ?>" class="page-btn">← Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <?php if ($i == $current_page): ?>
                                            <span class="page-btn active"><?= $i ?></span>
                                        <?php else: ?>
                                            <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>page=<?= $i ?>" class="page-btn"><?= $i ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>page=<?= $current_page + 1 ?>" class="page-btn">Next →</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Students Tab Content -->
                    <div id="students-tab" class="tab-content">
                        <!-- Search and Filters -->
                        <div class="search-filters">
                            <form method="GET" style="display: flex; gap: 1rem; align-items: center; width: 100%;">
                                <input type="hidden" name="tab" value="students">
                                <input type="text" name="search" placeholder="Search students..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="search-btn">🔍</button>
                            </form>
                        </div>

                        <!-- Student Artists Table -->
                        <div class="table-container">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($student_artists)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                                                <?php if (!empty($search)): ?>
                                                    No student artists found matching your criteria.
                                                <?php else: ?>
                                                    No student artists found yet.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($student_artists as $index => $student): ?>
                                            <tr data-user-id="<?= $student['id'] ?>" data-source-table="<?= $student['source_table'] ?>">
                                                <td><?= $students_offset + $index + 1 ?></td>
                                                <td><?= htmlspecialchars(trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'])) ?></td>
                                                <td><?= htmlspecialchars($student['email']) ?></td>
                                                <td>
                                                    <span class="role-badge role-<?= $student['role'] ?>">
                                                        <?= strtoupper($student['role']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= $student['status'] ?>">
                                                        <?= strtoupper($student['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-btn btn-edit" onclick="editUser(<?= $student['id'] ?>, '<?= $student['source_table'] ?>')">Edit</button>
                                                    <?php if ($student['status'] === 'active'): ?>
                                                        <button class="action-btn btn-suspend" onclick="suspendUser(<?= $student['id'] ?>, '<?= $student['source_table'] ?>')">Suspend</button>
                                                    <?php else: ?>
                                                        <button class="action-btn btn-activate" onclick="activateUser(<?= $student['id'] ?>, '<?= $student['source_table'] ?>')">Activate</button>
                                                    <?php endif; ?>
                                                    <button class="action-btn btn-archive" onclick="archiveUser(<?= $student['id'] ?>, '<?= $student['source_table'] ?>')">Archive</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Student Artists Count and Pagination -->
                        <div style="margin-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                            <div style="color: #666;">
                                Total Students: <?= $total_student_artists ?> (Showing <?= count($student_artists) ?> of <?= $total_student_artists ?>)
                            </div>
                            <?php if ($total_students_pages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    $query_params = $_GET;
                                    unset($query_params['students_page']);
                                    $base_url = '?' . http_build_query($query_params);
                                    if (empty($query_params)) $base_url = '?';
                                    ?>
                                    
                                    <?php if ($students_page > 1): ?>
                                        <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>students_page=<?= $students_page - 1 ?>" class="page-btn">← Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_students_pages; $i++): ?>
                                        <?php if ($i == $students_page): ?>
                                            <span class="page-btn active"><?= $i ?></span>
                                        <?php else: ?>
                                            <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>students_page=<?= $i ?>" class="page-btn"><?= $i ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($students_page < $total_students_pages): ?>
                                        <a href="<?= $base_url ?><?= !empty($query_params) ? '&' : '' ?>students_page=<?= $students_page + 1 ?>" class="page-btn">Next →</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Archive Tab Content -->
                    <div id="archive-tab" class="tab-content">
                        <!-- Search and Filters -->
                        <div class="search-filters">
                            <form method="GET" style="display: flex; gap: 1rem; align-items: center; width: 100%;">
                                <input type="hidden" name="tab" value="archive">
                                <input type="text" name="search" placeholder="Search archived users..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="search-btn">🔍</button>
                            </form>
                        </div>

                        <!-- Archive Table -->
                        <div class="table-container">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Get archived (inactive) users from both tables
                                    $archived_users = [];
                                    
                                    // Get archived (inactive) regular users
                                    $archived_where_conditions = ["status = 'inactive'"];
                                    $archived_params = [];
                                    
                                    if (!empty($search)) {
                                        $archived_where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
                                        $search_param = "%$search%";
                                        $archived_params[] = $search_param;
                                        $archived_params[] = $search_param;
                                        $archived_params[] = $search_param;
                                        $archived_params[] = $search_param;
                                    }
                                    
                                    $archived_where_clause = 'WHERE ' . implode(' AND ', $archived_where_conditions);
                                    
                                    $sql_archived_users = "SELECT id, first_name, middle_name, last_name, email, role, status, last_login, created_at, 'users' as source_table FROM users $archived_where_clause ORDER BY id ASC";
                                    $stmt = $pdo->prepare($sql_archived_users);
                                    $stmt->execute($archived_params);
                                    $archived_regular_users = $stmt->fetchAll();
                                    
                                    // Get archived student artists
                                    $sql_archived_students = "SELECT id, first_name, middle_name, last_name, email, 'student' as role, status, NULL as last_login, created_at, 'student_artists' as source_table FROM student_artists $archived_where_clause ORDER BY id ASC";
                                    $stmt = $pdo->prepare($sql_archived_students);
                                    $stmt->execute($archived_params);
                                    $archived_student_artists = $stmt->fetchAll();
                                    
                                    // Combine archived users
                                    $archived_users = array_merge($archived_regular_users, $archived_student_artists);
                                    ?>
                                    
                                    <?php if (empty($archived_users)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: #666;">
                                                <?php if (!empty($search)): ?>
                                                    No archived users found matching your criteria.
                                                <?php else: ?>
                                                    No archived users found.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($archived_users as $index => $archived_user): ?>
                                            <tr data-user-id="<?= $archived_user['id'] ?>" data-source-table="<?= $archived_user['source_table'] ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td><?= htmlspecialchars(trim($archived_user['first_name'] . ' ' . ($archived_user['middle_name'] ? $archived_user['middle_name'] . ' ' : '') . $archived_user['last_name'])) ?></td>
                                                <td><?= htmlspecialchars($archived_user['email']) ?></td>
                                                <td>
                                                    <span class="role-badge role-<?= $archived_user['role'] ?>">
                                                        <?= strtoupper($archived_user['role']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= $archived_user['status'] ?>">
                                                        <?= strtoupper($archived_user['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-btn btn-activate" onclick="activateUser(<?= $archived_user['id'] ?>, '<?= $archived_user['source_table'] ?>')">Restore</button>
                                                    <button class="action-btn btn-delete" onclick="permanentDeleteUser(<?= $archived_user['id'] ?>, '<?= $archived_user['source_table'] ?>')">Delete Permanently</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Archive Count -->
                        <div style="margin-top: 1rem; color: #666;">
                            Total Archived Users: <?= count($archived_users) ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- System Settings Section -->
            <section class="content-section" id="settings">
                <div class="page-header">
                    <h1 class="page-title">System Settings</h1>
                </div>

                <form id="systemSettingsForm" class="settings-form" autocomplete="off" onsubmit="updateSystemSettings(event)">
                    <div class="form-group">
                        <label class="form-label">Admin Email</label>
                        <input type="email" name="admin_email" class="form-input" value="admin@g.batstate-u.edu.ph" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Admin Password</label>
                        <input type="password" name="admin_password" class="form-input" autocomplete="new-password" placeholder="Enter a new password">
                        <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">Leave blank to keep the current password. For security, the current password is never displayed.</small>
                    </div>
                    
                    <div class="show-password-container">
                        <input type="checkbox" id="showAdminPassword" onchange="toggleAdminPassword()">
                        <label for="showAdminPassword">Show password</label>
                    </div>
                    
                    <button type="submit" class="save-btn">Save Settings</button>
                </form>
            </section>
        </main>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New User</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="addUserForm" onsubmit="addUser(event)">
                <div id="userForm">
                    <div class="form-group">
                        <label for="firstName">First Name *</label>
                        <input type="text" id="firstName" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="middleName">Middle Name</label>
                        <input type="text" id="middleName" name="middle_name">
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name *</label>
                        <input type="text" id="lastName" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required pattern=".*@g.batstate-u\.edu\.ph$" title="Must be a valid BatStateU email">
                        <small>Must be a valid @g.batstate-u.edu.ph email</small>
                    </div>
                    <div class="form-group" id="srCodeGroup" style="display: none;">
                        <label for="srCode">SR Code</label>
                        <input type="text" id="srCode" name="sr_code" pattern="^\d{2}-\d{5}$" title="Format: XX-XXXXX (e.g., 21-12345)">
                        <small>Format: XX-XXXXX (e.g., 21-12345)</small>
                    </div>
                    <div class="form-group">
                        <label for="role">Role *</label>
                        <select id="role" name="role" required onchange="toggleSrCodeField()">
                            <option value="">Select Role</option>
                            <option value="director">Director</option>
                            <option value="head">Head</option>
                            <option value="student">Student</option>
                        </select>
                    </div>
                    <div class="form-group" id="campusGroup">
                        <label for="campus">Campus *</label>
                        <select id="campus" name="campus" required>
                            <option value="">Select Campus</option>
                            <option value="Pablo Borbon">Pablo Borbon</option>
                            <option value="Alangilan">Alangilan</option>
                            <option value="Lipa">Lipa</option>
                            <option value="Nasugbu">Nasugbu</option>
                            <option value="Malvar">Malvar</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="6" maxlength="12">
                        <small>Must be 6-12 characters with at least one letter and one number</small>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password *</label>
                        <input type="password" id="confirmPassword" name="confirm_password" required minlength="6" maxlength="12">
                    </div>
                    <div class="show-password-container">
                        <input type="checkbox" id="showAddPassword" onchange="toggleAddPasswords()">
                        <label for="showAddPassword">Show Password</label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="save-btn">Add User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form id="editUserForm" onsubmit="updateUser(event)">
                <input type="hidden" id="editUserId" name="user_id">
                <input type="hidden" id="editSourceTable" name="source_table">
                <div id="editUserFormContent">
                    <div class="form-group">
                        <label for="editFirstName">First Name *</label>
                        <input type="text" id="editFirstName" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="editMiddleName">Middle Name</label>
                        <input type="text" id="editMiddleName" name="middle_name">
                    </div>
                    <div class="form-group">
                        <label for="editLastName">Last Name *</label>
                        <input type="text" id="editLastName" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="editEmail">Email *</label>
                        <input type="email" id="editEmail" name="email" required pattern=".*@g.batstate-u\.edu\.ph$" title="Must be a valid BatStateU email">
                        <small>Must be a valid @g.batstate-u.edu.ph email</small>
                    </div>
                    <div class="form-group">
                        <label for="editRole">Role *</label>
                        <select id="editRole" name="role" required onchange="toggleEditCampusField()">
                            <option value="">Select Role</option>
                            <option value="head">Head</option>
                            <option value="director">Director</option>
                        </select>
                    </div>
                    <div class="form-group" id="editCampusGroup">
                        <label for="editCampus">Campus *</label>
                        <select id="editCampus" name="campus" required>
                            <option value="">Select Campus</option>
                            <option value="Pablo Borbon">Pablo Borbon</option>
                            <option value="Alangilan">Alangilan</option>
                            <option value="Lipa">Lipa</option>
                            <option value="Nasugbu">Nasugbu</option>
                            <option value="Malvar">Malvar</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editPassword">New Password (optional)</label>
                        <input type="password" id="editPassword" name="password" minlength="6" maxlength="12">
                        <small>Leave blank to keep current password. Must be 6-12 characters with at least one letter and one number</small>
                    </div>
                    <div class="form-group">
                        <label for="editConfirmPassword">Confirm New Password</label>
                        <input type="password" id="editConfirmPassword" name="confirm_password" minlength="6" maxlength="12">
                    </div>
                    <div class="show-password-container">
                        <input type="checkbox" id="showEditPassword" onchange="toggleEditPasswords()">
                        <label for="showEditPassword">Show Password</label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="save-btn">Update User</button>
                    </div>
                </div>
            </form>
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

        // Load current system settings
        function loadSystemSettings() {
            const formData = new FormData();
            formData.append('action', 'get_system_settings');
            const passwordField = document.querySelector('input[name="admin_password"]');
            if (passwordField) {
                passwordField.value = '';
            }

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const settings = data.settings;
                    document.querySelector('input[name="admin_email"]').value = settings.admin_email || 'admin@g.batstate-u.edu.ph';
                }
            })
            .catch(error => {
                console.error('Error loading system settings:', error);
            });
        }

        // Navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Load system settings on page load
            loadSystemSettings();
            
            const navLinks = document.querySelectorAll('.nav-link');
            const contentSections = document.querySelectorAll('.content-section');

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
                    }
                });
            });
        });

        // Modal Functions
        function openModal() {
            document.getElementById('addUserModal').style.display = 'block';
            preventBackgroundScroll();
        }

        function closeModal() {
            document.getElementById('addUserModal').style.display = 'none';
            document.getElementById('addUserForm').reset();
            allowBackgroundScroll();
        }

        function openEditModal() {
            document.getElementById('editUserModal').style.display = 'block';
            preventBackgroundScroll();
        }

        function closeEditModal() {
            document.getElementById('editUserModal').style.display = 'none';
            document.getElementById('editUserForm').reset();
            allowBackgroundScroll();
        }

        // Click outside modal to close
        window.onclick = function(event) {
            const addModal = document.getElementById('addUserModal');
            const editModal = document.getElementById('editUserModal');
            if (event.target === addModal) {
                closeModal();
            } else if (event.target === editModal) {
                closeEditModal();
            }
        }

        // Add User Function
        function addUser(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'add_user');
            
            // Validate password match
            if (formData.get('password') !== formData.get('confirm_password')) {
                alert('Passwords do not match!');
                return;
            }

            // Show loading state
            const submitBtn = event.target.querySelector('.save-btn');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Adding...';
            submitBtn.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('User added successfully!');
                    closeModal();
                    reloadWithTab(); // Refresh to show new user
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error adding user: ' + error.message);
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Edit User Function
        function editUser(userId, sourceTable = 'users') {
            // Fetch user data
            const formData = new FormData();
            formData.append('action', 'get_user');
            formData.append('user_id', userId);
            formData.append('source_table', sourceTable);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const user = data.user;
                    
                    // Debug: Log the user data
                    console.log('Edit User Data:', user);
                    console.log('Role value:', user.role);
                    
                    // Populate edit form
                    document.getElementById('editUserId').value = user.id;
                    document.getElementById('editSourceTable').value = sourceTable;
                    document.getElementById('editFirstName').value = user.first_name;
                    document.getElementById('editMiddleName').value = user.middle_name || '';
                    document.getElementById('editLastName').value = user.last_name;
                    document.getElementById('editEmail').value = user.email;
                    document.getElementById('editRole').value = user.role || '';
                    document.getElementById('editCampus').value = user.campus || '';
                    document.getElementById('editPassword').value = '';
                    document.getElementById('editConfirmPassword').value = '';
                    
                    // Toggle campus field visibility based on role
                    toggleEditCampusField();
                    
                    openEditModal();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error fetching user data: ' + error.message);
            });
        }

        // Update User Function
        function updateUser(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_user');
            
            // Debug: Log form data
            console.log('=== UPDATE USER FORM DATA ===');
            for (let [key, value] of formData.entries()) {
                console.log(key + ': ' + value);
            }
            console.log('=== END FORM DATA ===');
            
            // Validate password match if password is provided
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');
            
            if (password && password !== confirmPassword) {
                alert('Passwords do not match!');
                return;
            }

            // Show loading state
            const submitBtn = event.target.querySelector('.save-btn');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('User updated successfully!');
                    closeEditModal();
                    reloadWithTab(); // Refresh to show updated data
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating user: ' + error.message);
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // User Management Functions
        function suspendUser(userId, sourceTable = 'users') {
            if (confirm('Are you sure you want to suspend this user?')) {
                updateUserStatus(userId, 'suspended', sourceTable);
            }
        }

        function activateUser(userId, sourceTable = 'users') {
            if (confirm('Are you sure you want to activate this user?')) {
                updateUserStatus(userId, 'active', sourceTable);
            }
        }

        function archiveUser(userId, sourceTable = 'users') {
            if (confirm('Are you sure you want to archive this user? They will be moved to the Archive tab.')) {
                const formData = new FormData();
                formData.append('action', 'archive_user');
                formData.append('user_id', userId);
                formData.append('source_table', sourceTable);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('User archived successfully!');
                        reloadWithTab();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error archiving user: ' + error.message);
                });
            }
        }

        // Permanent delete function for archived users
        function permanentDeleteUser(userId, sourceTable = 'users') {
            if (confirm('Are you sure you want to permanently delete this user? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'permanent_delete_user');
                formData.append('user_id', userId);
                formData.append('source_table', sourceTable);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('User permanently deleted!');
                        reloadWithTab();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error deleting user: ' + error.message);
                });
            }
        }

        function updateUserStatus(userId, status, sourceTable = 'users') {
            const actionMap = {
                'suspended': 'suspend_user',
                'active': 'activate_user'
            };
            
            const formData = new FormData();
            formData.append('action', actionMap[status]);
            formData.append('user_id', userId);
            formData.append('source_table', sourceTable);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`User ${status} successfully!`);
                    reloadWithTab();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating user: ' + error.message);
            });
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                // Send logout request to clear session
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=logout'
                })
                .then(() => {
                    window.location.href = '../index.php';
                })
                .catch(() => {
                    // Even if logout request fails, redirect to login
                    window.location.href = '../index.php';
                });
            }
        }

        // Settings form submission function
        function updateSystemSettings(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'update_system_settings');
            
            // Show loading state
            const submitBtn = event.target.querySelector('.save-btn');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Saving...';
            submitBtn.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('System settings updated successfully!');
                    // Optionally reload to reflect changes
                    reloadWithTab();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating system settings: ' + error.message);
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Toggle admin password visibility
        function toggleAdminPassword() {
            const checkbox = document.getElementById('showAdminPassword');
            const passwordField = document.querySelector('input[name="admin_password"]');
            
            if (passwordField) {
                passwordField.type = checkbox.checked ? 'text' : 'password';
            }
        }

        // Add event listeners for action buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('add-user-btn')) {
                openModal();
            }
        });

        function toggleSrCodeField() {
            const role = document.getElementById('role').value;
            const srCodeGroup = document.getElementById('srCodeGroup');
            const srCodeInput = document.getElementById('srCode');
            const campusGroup = document.getElementById('campusGroup');
            const campusInput = document.getElementById('campus');
            
            if (role === 'student') {
                srCodeGroup.style.display = 'block';
                srCodeInput.required = true;
                campusGroup.style.display = 'none';
                campusInput.required = false;
                campusInput.value = '';
            } else if (role === 'director') {
                srCodeGroup.style.display = 'none';
                srCodeInput.required = false;
                srCodeInput.value = '';
                campusGroup.style.display = 'none';
                campusInput.required = false;
                campusInput.value = '';
            } else {
                srCodeGroup.style.display = 'none';
                srCodeInput.required = false;
                srCodeInput.value = '';
                campusGroup.style.display = 'block';
                campusInput.required = true;
            }
        }

        function toggleEditCampusField() {
            const role = document.getElementById('editRole').value;
            const campusGroup = document.getElementById('editCampusGroup');
            const campusInput = document.getElementById('editCampus');
            
            if (role === 'director') {
                campusGroup.style.display = 'none';
                campusInput.required = false;
                campusInput.value = '';
            } else {
                campusGroup.style.display = 'block';
                campusInput.required = true;
            }
        }

        // Show/hide password functions
        function toggleAddPasswords() {
            const checkbox = document.getElementById('showAddPassword');
            const passwordFields = ['password', 'confirmPassword'];
            
            passwordFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.type = checkbox.checked ? 'text' : 'password';
                }
            });
        }

        function toggleEditPasswords() {
            const checkbox = document.getElementById('showEditPassword');
            const passwordFields = ['editPassword', 'editConfirmPassword'];
            
            passwordFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.type = checkbox.checked ? 'text' : 'password';
                }
            });
        }

        // Enhanced password validation (for user self-service, not admin)
        function validatePassword(password) {
            const hasLetter = /[A-Za-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const validLength = password.length >= 6 && password.length <= 12;
            
            return {
                valid: hasLetter && hasNumber && validLength,
                hasLetter,
                hasNumber,
                validLength
            };
        }

        // Simple admin password validation (no complexity requirements)
        function validateAdminPassword(password) {
            return {
                valid: password.length > 0,
                hasLetter: true,
                hasNumber: true,
                validLength: password.length > 0
            };
        }

        // Add password validation listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Add User Form validation (use simple validation for admin)
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirmPassword');
            
            if (passwordField) {
                passwordField.addEventListener('input', function() {
                    const password = this.value;
                    const validation = validateAdminPassword(password); // Use simple validation for admin
                    
                    if (password && !validation.validLength) {
                        this.setCustomValidity('Password is required');
                    } else {
                        this.setCustomValidity('');
                    }
                    
                    // Check password confirmation match
                    if (confirmPasswordField && confirmPasswordField.value && password !== confirmPasswordField.value) {
                        confirmPasswordField.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPasswordField.setCustomValidity('');
                    }
                });
            }
            
            if (confirmPasswordField) {
                confirmPasswordField.addEventListener('input', function() {
                    const password = passwordField.value;
                    const confirmPassword = this.value;
                    
                    if (password !== confirmPassword) {
                        this.setCustomValidity('Passwords do not match');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            // Edit User Form validation
            const editPasswordField = document.getElementById('editPassword');
            const editConfirmPasswordField = document.getElementById('editConfirmPassword');
            
            if (editPasswordField) {
                editPasswordField.addEventListener('input', function() {
                    const password = this.value;
                    if (password) { // Only validate if password is provided
                        const validation = validatePassword(password);
                        
                        if (!validation.validLength) {
                            this.setCustomValidity('Password must be 6-12 characters long');
                        } else if (!validation.hasLetter) {
                            this.setCustomValidity('Password must contain at least one letter');
                        } else if (!validation.hasNumber) {
                            this.setCustomValidity('Password must contain at least one number');
                        } else {
                            this.setCustomValidity('');
                        }
                        
                        // Check password confirmation match
                        if (editConfirmPasswordField && editConfirmPasswordField.value && password !== editConfirmPasswordField.value) {
                            editConfirmPasswordField.setCustomValidity('Passwords do not match');
                        } else if (validation.valid) {
                            editConfirmPasswordField.setCustomValidity('');
                        }
                    } else {
                        this.setCustomValidity('');
                        editConfirmPasswordField.setCustomValidity('');
                    }
                });
            }
            
            if (editConfirmPasswordField) {
                editConfirmPasswordField.addEventListener('input', function() {
                    const password = editPasswordField.value;
                    const confirmPassword = this.value;
                    
                    if (password && password !== confirmPassword) {
                        this.setCustomValidity('Passwords do not match');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }
        });

        // Tab switching functionality
        // Helper function to reload page while preserving tab
        function reloadWithTab() {
            const urlParams = new URLSearchParams(window.location.search);
            const currentTab = urlParams.get('tab') || 'staffs';
            window.location.href = '?tab=' + currentTab;
        }

        function switchTab(event, tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all tab links
            const tabLinks = document.querySelectorAll('.tab-link');
            tabLinks.forEach(link => {
                link.classList.remove('active');
            });

            // Show the selected tab
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to the clicked tab link
            event.target.classList.add('active');
            
            // Update URL with tab parameter without reloading
            const url = new URL(window.location);
            const tabParam = tabName.replace('-tab', '');
            url.searchParams.set('tab', tabParam);
            window.history.pushState({}, '', url);
        }

        // Initialize the correct tab based on URL parameter or default to first tab
        document.addEventListener('DOMContentLoaded', function() {
            // Get the tab parameter from URL
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab');
            
            // Determine which tab to show
            let tabToShow = 'staffs-tab'; // default
            let tabLinkIndex = 0; // default to first tab link
            
            if (activeTab === 'students') {
                tabToShow = 'students-tab';
                tabLinkIndex = 1;
            } else if (activeTab === 'archive') {
                tabToShow = 'archive-tab';
                tabLinkIndex = 2;
            }
            
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tab links
            const tabLinks = document.querySelectorAll('.tab-link');
            tabLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Show the selected tab
            const targetTab = document.getElementById(tabToShow);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Activate the corresponding tab link
            if (tabLinks[tabLinkIndex]) {
                tabLinks[tabLinkIndex].classList.add('active');
            }
        });
    </script>
    <?php $chatbotBasePath = '../'; include __DIR__ . '/../components/oca-chatbot.php'; ?>
</body>
</html>
