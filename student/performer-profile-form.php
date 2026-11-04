<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>

<?php
session_start();
include("db_connect.php");
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../services/OtpService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_action'])) {
    header('Content-Type: application/json');
    try {
        $otpPdo = getDBConnection();
        $otpAction = $_POST['otp_action'];
        $otpEmail = trim($_POST['email'] ?? '');
        if ($otpAction === 'start') {
            if (!filter_var($otpEmail, FILTER_VALIDATE_EMAIL) || !registrationEmailIsAllowed($otpEmail)) {
                throw new RuntimeException('Use a valid BatStateU G-Suite email address.');
            }
            $duplicate = $otpPdo->prepare("SELECT email FROM users WHERE BINARY email = ? UNION SELECT email FROM student_artists WHERE BINARY email = ? UNION SELECT email FROM applications WHERE BINARY email = ? AND application_status NOT IN ('rejected') LIMIT 1");
            $duplicate->execute([$otpEmail, $otpEmail, $otpEmail]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('This email already has an account or pending application.');
            }
            $challenge = OtpService::issue($otpPdo, $otpEmail, 'registration');
            $_SESSION['performer_otp_challenge'] = $challenge;
            $_SESSION['performer_otp_email'] = $otpEmail;
            echo json_encode(['success' => true, 'message' => 'Verification code sent.', 'expires_at' => $challenge['expires_at'], 'resend_at' => $challenge['resend_at'], 'resend_count' => 0]);
            exit;
        }
        if ($otpAction === 'verify') {
            $challenge = $_SESSION['performer_otp_challenge'] ?? null;
            if (!$challenge || empty($_SESSION['performer_otp_email']) || strcasecmp($_SESSION['performer_otp_email'], $otpEmail) !== 0) {
                throw new RuntimeException('Your verification request is no longer valid.');
            }
            OtpService::verify($otpPdo, (int)$challenge['id'], $challenge['token'], 'registration', trim($_POST['otp'] ?? ''));
            $_SESSION['performer_email_verified'] = strtolower($otpEmail);
            unset($_SESSION['performer_otp_challenge'], $_SESSION['performer_otp_email']);
            echo json_encode(['success' => true, 'message' => 'Email verified.']);
            exit;
        }
        if ($otpAction === 'resend') {
            $challenge = $_SESSION['performer_otp_challenge'] ?? null;
            if (!$challenge || empty($_SESSION['performer_otp_email']) || strcasecmp($_SESSION['performer_otp_email'], $otpEmail) !== 0) {
                throw new RuntimeException('Your verification request is no longer valid.');
            }
            $newChallenge = OtpService::issue($otpPdo, $otpEmail, 'registration', null, null, (int)$challenge['id']);
            $_SESSION['performer_otp_challenge'] = $newChallenge;
            echo json_encode(['success' => true, 'message' => 'A new verification code was sent.', 'expires_at' => $newChallenge['expires_at'], 'resend_at' => $newChallenge['resend_at'], 'resend_count' => $newChallenge['resend_count'], 'locked_until' => $newChallenge['locked_until']]);
            exit;
        }
        throw new RuntimeException('Invalid verification action.');
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submittedEmail = strtolower(trim($_POST['email'] ?? ''));
    if (($_SESSION['performer_email_verified'] ?? '') !== $submittedEmail) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Verify your email before submitting the application.']);
        exit;
    }
    // Log form submission for debugging
    error_log("Form submitted - POST data: " . print_r($_POST, true));
    
    try {
        // Sanitize and collect form data
        // performance_type may be sent as an array (performance_type[]) so handle both
        if (isset($_POST["performance_type"])) {
            $performance_type = is_array($_POST["performance_type"]) ? implode(',', $_POST["performance_type"]) : $_POST["performance_type"];
        } else {
            $performance_type = '';
        }
        $consent = $_POST["consent"] ?? '';
        $first_name = $_POST["first_name"] ?? '';
        $middle_name = $_POST["middle_name"] ?? '';
        $last_name = $_POST["last_name"] ?? '';
        $full_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name);
        $address = $_POST["address"] ?? '';
        $present_address = $_POST["present_address"] ?? '';
        $date_of_birth = $_POST["date_of_birth"] ?? '';
        $age = $_POST["age"] ?? '';
        $gender = $_POST["gender"] ?? '';
        $place_of_birth = $_POST["place_of_birth"] ?? '';
        $email = $_POST["email"] ?? '';
        $contact_number = $_POST["contact_number"] ?? '';
        $father_name = $_POST["father_name"] ?? '';
        $mother_name = $_POST["mother_name"] ?? '';
        $guardian = $_POST["guardian"] ?? '';
        $guardian_contact = $_POST["guardian_contact"] ?? '';
        $campus = $_POST["campus"] ?? '';
        $college = $_POST["college"] ?? '';
        $sr_code = $_POST["sr_code"] ?? '';
        $year_level = $_POST["year_level"] ?? '';
        $program = $_POST["program"] ?? '';
        $first_semester_units = (int)($_POST["first_semester_units"] ?? 0);
        $second_semester_units = (int)($_POST["second_semester_units"] ?? 0);
        
        // Handle instructors array
        $instructors = '';
        if (isset($_POST['instructors']) && is_array($_POST['instructors'])) {
            // Filter out empty values and join with comma
            $instructors_array = array_filter($_POST['instructors'], function($value) {
                return !empty(trim($value));
            });
            $instructors = implode(', ', $instructors_array);
        }
        
        $certification = isset($_POST["certification"]) ? 1 : 0;
        $signature_date = $_POST["signature_date"] ?? '';

        // Handle file uploads
        $profile_photo_path = null;
        $signature_image_path = null;
        
        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . '/../uploads/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Handle profile photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_file = $_FILES['photo'];
            $photo_ext = strtolower(pathinfo($photo_file['name'], PATHINFO_EXTENSION));
            $allowed_photo_types = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($photo_ext, $allowed_photo_types)) {
                $photo_filename = 'profile_' . uniqid() . '.' . $photo_ext;
                $photo_path = $upload_dir . $photo_filename;
                
                if (move_uploaded_file($photo_file['tmp_name'], $photo_path)) {
                    $profile_photo_path = 'uploads/' . $photo_filename;
                    error_log("Profile photo uploaded: " . $profile_photo_path);
                } else {
                    error_log("Failed to move uploaded photo file");
                }
            }
        }
        
        // Handle signature image from canvas
        if (isset($_POST['signature_data']) && !empty($_POST['signature_data'])) {
            $signature_data = $_POST['signature_data'];
            // Remove data:image/png;base64, prefix if present
            $signature_data = preg_replace('#^data:image/[^;]+;base64,#', '', $signature_data);
            $signature_binary = base64_decode($signature_data);
            
            if ($signature_binary !== false && strlen($signature_binary) > 0) {
                $signature_filename = 'signature_' . uniqid() . '.png';
                $signature_path = $upload_dir . $signature_filename;
                
                if (file_put_contents($signature_path, $signature_binary)) {
                    $signature_image_path = 'uploads/' . $signature_filename;
                    error_log("Signature image saved: " . $signature_image_path);
                } else {
                    error_log("Failed to save signature image");
                }
            } else {
                error_log("Invalid signature data received");
            }
        }

        // Log the collected data
        error_log("Performance type captured: " . $performance_type);
        error_log("First semester units: " . $first_semester_units);
        error_log("Second semester units: " . $second_semester_units);

    // Insert data into database
    $stmt = $conn->prepare("
        INSERT INTO applications (
            performance_type, consent, first_name, middle_name, last_name, full_name, address, present_address, 
            date_of_birth, age, gender, place_of_birth, email, contact_number, father_name, mother_name, 
            guardian, guardian_contact, campus, college, sr_code, year_level, program, first_semester_units, 
            second_semester_units, instructors, profile_photo, signature_image, certification, signature_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Parameter types: 23 strings, 2 integers, 1 string (instructors), 2 strings, 1 integer, 1 string = 30 total
    // s(23) + i + i + s + s + s + i + s
    $types = 'sssssssssssssssssssssss' . 'ii' . 's' . 'ss' . 'i' . 's';

    $stmt->bind_param(
        $types,
        $performance_type, $consent, $first_name, $middle_name, $last_name, $full_name, $address, $present_address, 
        $date_of_birth, $age, $gender, $place_of_birth, $email, $contact_number, $father_name, $mother_name, 
        $guardian, $guardian_contact, $campus, $college, $sr_code, $year_level, $program, 
        $first_semester_units, $second_semester_units, $instructors, $profile_photo_path, $signature_image_path,
        $certification, $signature_date
    );

    $response = ['success' => false, 'message' => 'Unknown error'];

    try {
        if ($stmt->execute()) {
            $application_id = $conn->insert_id;
            error_log("Application inserted successfully with ID: " . $application_id);
            
            // Insert participation records if provided
            if (isset($_POST['participation_date']) && is_array($_POST['participation_date'])) {
                $participation_stmt = $conn->prepare("
                    INSERT INTO application_participation (application_id, participation_date, event_name, participation_level, rank_award) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                for ($i = 0; $i < count($_POST['participation_date']); $i++) {
                    $part_date = $_POST['participation_date'][$i] ?? '';
                    $part_event = $_POST['participation_event'][$i] ?? '';
                    $part_level = $_POST['participation_level'][$i] ?? '';
                    $part_rank = $_POST['participation_rank'][$i] ?? '';
                    
                    // Only insert if at least one field has data
                    if (!empty($part_date) || !empty($part_event) || !empty($part_level) || !empty($part_rank)) {
                        $participation_stmt->bind_param("issss", $application_id, $part_date, $part_event, $part_level, $part_rank);
                        if (!$participation_stmt->execute()) {
                            error_log("Error inserting participation record: " . $participation_stmt->error);
                        }
                    }
                }
                $participation_stmt->close();
            }
            
            // Insert competition records if provided (NEW - Section IV)
            if (isset($_POST['competition_date']) && is_array($_POST['competition_date'])) {
                $competition_stmt = $conn->prepare("
                    INSERT INTO application_competition (application_id, competition_date, event_name, competition_level, rank_award) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                for ($i = 0; $i < count($_POST['competition_date']); $i++) {
                    $comp_date = $_POST['competition_date'][$i] ?? '';
                    $comp_event = $_POST['competition_event'][$i] ?? '';
                    $comp_level = $_POST['competition_level'][$i] ?? '';
                    $comp_rank = $_POST['competition_rank'][$i] ?? '';
                    
                    // Only insert if at least one field has data
                    if (!empty($comp_date) || !empty($comp_event) || !empty($comp_level) || !empty($comp_rank)) {
                        $competition_stmt->bind_param("issss", $application_id, $comp_date, $comp_event, $comp_level, $comp_rank);
                        if (!$competition_stmt->execute()) {
                            error_log("Error inserting competition record: " . $competition_stmt->error);
                        }
                    }
                }
                $competition_stmt->close();
            }
            
            // Insert affiliation records if provided
            if (isset($_POST['affiliation_position']) && is_array($_POST['affiliation_position'])) {
                $affiliation_stmt = $conn->prepare("
                    INSERT INTO application_affiliations (application_id, position, organization, years_active) 
                    VALUES (?, ?, ?, ?)
                ");
                
                for ($i = 0; $i < count($_POST['affiliation_position']); $i++) {
                    $aff_position = $_POST['affiliation_position'][$i] ?? '';
                    $aff_org = $_POST['affiliation_organization'][$i] ?? '';
                    $aff_years = $_POST['affiliation_years'][$i] ?? '';
                    
                    // Only insert if at least one field has data
                    if (!empty($aff_position) || !empty($aff_org) || !empty($aff_years)) {
                        $affiliation_stmt->bind_param("isss", $application_id, $aff_position, $aff_org, $aff_years);
                        if (!$affiliation_stmt->execute()) {
                            error_log("Error inserting affiliation record: " . $affiliation_stmt->error);
                        }
                    }
                }
                $affiliation_stmt->close();
            }
            
            unset($_SESSION['performer_email_verified']);
            $response = ['success' => true, 'message' => 'Application submitted successfully', 'application_id' => $application_id];
        } else {
            $error_msg = $stmt->error;
            error_log("Database error: " . $error_msg);
            $response = ['success' => false, 'message' => 'Database error: ' . $error_msg];
        }
    } catch (Exception $e) {
        error_log("Exception during form processing: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'An error occurred while processing your application. Please try again.'];
    }

    $stmt->close();

    // If request is AJAX (fetch), return JSON; otherwise, fall back to inline JS
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    // Modern fetch API doesn't always set X-Requested-With, so also check for JSON accept header
    if ($isAjax || $acceptsJson) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if ($response['success']) {
        echo "<script>alert('Application submitted successfully! Your application ID is: " . ($response['application_id'] ?? 'N/A') . "\\n\\nIMPORTANT: Please use your SR Code to check your application status later.'); window.location.href='performer-profile-form.php';</script>";
    } else {
        echo "<script>alert('Error: " . addslashes($response['message']) . "');</script>";
    }
    
    } catch (Exception $e) {
        error_log("Form processing error: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'An error occurred while processing your application: ' . $e->getMessage()];
        
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

        if ($isAjax || $acceptsJson) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } else {
            echo "<script>alert('Error: " . addslashes($response['message']) . "');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performer's Profile Form - Culture and Arts - BatStateU TNEU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 2rem 1rem;
            line-height: 1.4;
            overflow-x: hidden;
        }

        .performer-profile-container {
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
            overflow-x: hidden;
        }

        .performer-profile-card {
            background: #fff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        /* Header Section */
        .form-header {
            background: #fff;
            padding: 1.5rem 2rem;
            border-bottom: 2px solid #333;
        }

        .header-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            border: 1px solid #333;
            padding: 1rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin-right: 1rem;
        }

        .reference-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.9rem;
        }

        .ref-item {
            display: flex;
            gap: 0.5rem;
        }

        .ref-item .label {
            font-weight: 600;
            min-width: 100px;
        }

        .form-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        /* Form Body */
        .form-body {
            padding: 2rem;
        }

        .form-section {
            margin-bottom: 2rem;
            border: 1px solid #ddd;
            padding: 1.5rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .instruction {
            font-weight: 400;
            font-style: italic;
            color: #666;
            font-size: 0.9rem;
        }

        /* Checkbox Grid */
        .checkbox-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .checkbox-item.full-width {
            grid-column: 1 / -1;
        }

        .checkbox-item input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 16px;
            height: 16px;
            border: 2px solid #333;
            background: #fff;
            position: relative;

            flex-shrink: 0;
        }

        .checkbox-item input[type="checkbox"]:checked + .checkmark::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }

        .inline-text {
            flex: 1;
            padding: 0.25rem 0.5rem;
            border: none;
            border-bottom: 1px solid #333;
            background: transparent;
            font-size: 0.85rem;
            margin-left: 0.5rem;
        }

        /* Privacy Note */
        .privacy-note {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
            font-size: 0.8rem;
            line-height: 1.5;
            text-align: justify;
        }

        /* Consent Section */
        .consent-section h4 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: #333;
        }

        .consent-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .radio-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .radio-item input[type="radio"] {
            display: none;
        }

        .radiomark {
            width: 16px;
            height: 16px;
            border: 2px solid #333;
            border-radius: 50%;
            background: #fff;
            position: relative;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .radio-item input[type="radio"]:checked + .radiomark::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: #333;
            border-radius: 50%;
        }

        .consent-text {
            font-size: 0.8rem;
            line-height: 1.4;
            color: #555;
            margin-left: 0.5rem;
        }

        /* Photo Section */
        .photo-section {
            float: right;
            margin-left: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .photo-placeholder {
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

        .photo-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .upload-photo-btn {
            background: #ff5a5a;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: background 0.3s ease;
            width: 120px;
        }

        .upload-photo-btn:hover {
            background: #ff3333;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
            margin-bottom: 0.5rem;
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
            font-size: 0.9rem;
            font-family: inherit;
            color: #000;
            box-sizing: border-box;
        }

        .form-group select:disabled {
            color: #999;
            background-color: #f5f5f5;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #ff5a5a;
            box-shadow: 0 0 0 2px rgba(255, 90, 90, 0.2);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .form-group.half {
            flex: 1;
        }

        .form-group.quarter {
            flex: 0.5;
        }

        .checkbox-row {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .checkbox-inline {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .checkbox-inline input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .inline-units {
            width: 90px !important;
            padding: 0.25rem 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
            font-size: 0.85rem;
            margin-left: 0.5rem;
            margin-right: 1rem;
            box-sizing: border-box;
        }

        /* Table Sections */
        .table-section {
            margin-top: 1rem;
        }

        .participation-table,
        .competition-table,
        .affiliation-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .participation-table th,
        .competition-table th,
        .affiliation-table th,
        .participation-table td,
        .competition-table td,
        .affiliation-table td {
            border: 1px solid #333;
            padding: 0.75rem 0.5rem;
            text-align: left;
        }

        .participation-table th,
        .competition-table th,
        .affiliation-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .sub-text {
            font-weight: 400;
            font-style: italic;
            font-size: 0.75rem;
        }

        .participation-table input,
        .participation-table select,
        .competition-table input,
        .competition-table select,
        .affiliation-table input {
            width: 100%;
            border: none;
            padding: 0.25rem;
            font-size: 0.85rem;
            background: transparent;
        }

        .participation-table input:focus,
        .participation-table select:focus,
        .affiliation-table input:focus {
            outline: 1px solid #ff5a5a;
            background: #fff;
        }

        .add-row-btn {
            background: #ff5a5a;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .add-row-btn:hover {
            background: #ff3333;
        }

        /* Instructors List */
        #instructorsList {
            margin-bottom: 0.5rem;
        }

        .instructor-item {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }

        .instructor-input {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .add-instructor-btn {
            background: #ff5a5a;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .add-instructor-btn:hover {
            background: #ff3333;
        }

        .remove-instructor-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background 0.3s ease;
            white-space: nowrap;
        }

        .remove-instructor-btn:hover {
            background: #c82333;
        }

        /* Certification */
        .certification {
            margin: 1.5rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 4px;
        }

        /* Signature Section */
        .signature-section {
            display: flex;
            justify-content: space-between;
            align-items: end;
            margin: 2rem 0;
            gap: 2rem;
        }

        .signature-field {
            flex: 2;
        }

        .signature-field label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .signature-canvas {
            border: 2px solid #333;
            background: #fff !important;
            cursor: crosshair;
            border-radius: 4px;
            width: 400px;
            height: 150px;
            display: block;
        }

        .signature-controls {
            margin-top: 0.5rem;
        }

        .clear-signature-btn {
            background: #666;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .clear-signature-btn:hover {
            background: #555;
        }

        .date-field {
            flex: 1;
            padding-bottom: 19%;
        }

        .date-field label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .date-field input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
        }

        /* Attachment Note */
        .attachment-note {
            background: #fff3cd;
            padding: 1rem;
            border-radius: 4px;
            border-left: 4px solid #ffc107;
            margin: 1rem 0;
        }

        .attachment-note p {
            font-size: 0.9rem;
            color: #856404;
            margin: 0;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #eee;
            flex-wrap: wrap;
        }

        .cancel-btn {
            background: transparent;
            color: #666;
            border: 2px solid #ddd;
            padding: 0.75rem 2rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .cancel-btn:hover {
            background: #f8f9fa;
            border-color: #ccc;
        }

        .submit-btn {
            background: linear-gradient(135deg, #ff5a5a, #ff7a6b);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(255, 90, 90, 0.3);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 90, 90, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Secondary Actions */
        .secondary-actions {
            display: flex;
            justify-content: center;
            margin-top: 1rem;
        }

        .status-btn {
            background: transparent;
            color: #666;
            border: 2px solid #ddd;
            padding: 0.75rem 2rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .status-btn:hover {
            background: #f8f9fa;
            border-color: #ccc;
        }

        /* Success Message */
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            text-align: center;
            font-weight: 500;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .performer-profile-container {
                padding: 1rem 0.5rem;
            }
            
            .form-header {
                padding: 1rem;
            }
            
            .header-info {
                flex-direction: column;
                gap: 1rem;
            }
            
            .form-body {
                padding: 1rem;
            }
            
            .checkbox-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                gap: 1rem;
            }
            
            .form-group.half,
            .form-group.quarter {
                flex: 1;
            }
            
            .signature-section {
                flex-direction: column;
                gap: 1rem;
            }
            
            .photo-section {
                float: none;
                margin: 0 auto 1rem auto;
                text-align: center;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .secondary-actions {
                margin-top: 0.5rem;
            }
            
            .participation-table,
            .competition-table,
            .affiliation-table {
                font-size: 0.8rem;
            }
            
            .participation-table th,
            .competition-table th,
            .affiliation-table th,
            .participation-table td,
            .competition-table td,
            .affiliation-table td {
                padding: 0.5rem 0.25rem;
            }
        }

        @media (max-width: 480px) {
            * {
                box-sizing: border-box;
            }
            
            body {
                padding: 0;
                margin: 0;
                overflow-x: hidden;
                width: 100%;
            }
            
            .performer-profile-container {
                padding: 0.5rem;
                margin: 0;
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .performer-profile-card {
                width: 100%;
                overflow: hidden;
            }
            
            .form-container {
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                width: 100%;
                overflow: hidden;
            }
            
            .form-header {
                padding: 0.75rem;
                overflow: hidden;
            }
            
            .header-info {
                gap: 0.5rem;
                flex-wrap: wrap;
                padding: 0.5rem;
            }
            
            .logo-section {
                flex-shrink: 0;
            }
            
            .logo {
                width: 45px;
                height: 45px;
            }
            
            .form-title {
                font-size: 1.1rem;
                line-height: 1.3;
            }
            
            .reference-info {
                font-size: 0.75rem;
            }
            
            .form-body {
                padding: 0.75rem;
            }
            
            .section-title {
                font-size: 0.95rem;
                padding: 0.5rem;
                margin-top: 1rem;
                margin-bottom: 0.75rem;
            }
            
            .form-section {
                margin-bottom: 0.75rem;
            }
            
            .form-row {
                gap: 0.75rem;
            }
            
            .form-group {
                margin-bottom: 0.75rem;
            }
            
            .form-group label {
                font-size: 0.85rem;
                margin-bottom: 0.35rem;
            }
            
            .form-control,
            input[type="text"],
            input[type="email"],
            input[type="date"],
            input[type="number"],
            input[type="tel"],
            select,
            textarea {
                padding: 0.6rem;
                font-size: 16px !important;
                border-radius: 6px;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            
            .checkbox-grid {
                gap: 0.5rem;
            }
            
            .checkbox-item {
                padding: 0.6rem;
                font-size: 0.85rem;
            }
            
            .checkbox-item input[type="checkbox"] {
                width: 16px;
                height: 16px;
            }
            
            .photo-section {
                width: 100%;
                margin-bottom: 1rem;
                float: none;
                margin-left: 0;
                display: flex;
                align-items: center;
                gap: 1rem;
                justify-content: space-between;
            }
            
            .photo-placeholder {
                width: 120px;
                height: 120px;
                flex-shrink: 0;
            }
            
            .upload-photo-btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
                white-space: nowrap;
                height: auto;
                flex: 0 0 auto;
            }
            
            .signature-section {
                flex-direction: column;
                gap: 1rem;
                width: 100%;
                overflow: hidden;
            }
            
            .signature-field {
                width: 100%;
            }
            
            .signature-canvas {
                width: 100% !important;
                max-width: 100%;
                height: 140px;
                box-sizing: border-box;
            }
            
            .signature-controls {
                gap: 0.5rem;
                display: flex;
                flex-wrap: wrap;
            }
            
            .signature-controls button,
            .clear-signature-btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
                flex: 1;
                min-width: 100px;
            }
            
            .date-field {
                width: 100%;
            }
            
            .date-field input {
                width: 100%;
                max-width: 100%;
            }
            
            .participation-table,
            .competition-table,
            .affiliation-table {
                font-size: 0.75rem;
                overflow-x: auto;
                display: block;
                width: 100%;
                -webkit-overflow-scrolling: touch;
            }
            
            .participation-table table,
            .competition-table table,
            .affiliation-table table {
                min-width: 600px;
                width: 100%;
            }
            
            .participation-table th,
            .competition-table th,
            .affiliation-table th,
            .participation-table td,
            .competition-table td,
            .affiliation-table td {
                padding: 0.4rem 0.3rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }
            
            .participation-table input,
            .competition-table input,
            .competition-table select,
            .affiliation-table input {
                padding: 0.4rem;
                font-size: 14px;
                min-width: 80px;
            }
            
            .add-row-btn {
                padding: 0.5rem;
                font-size: 0.85rem;
            }
            
            .checkbox-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .checkbox-inline {
                width: 100%;
            }
            
            .inline-units {
                width: 100%;
                max-width: 100%;
                margin-left: 0;
                margin-right: 0;
                padding: 0.6rem;
                font-size: 16px;
            }
            
            .form-actions {
                padding: 0.75rem;
                gap: 0.5rem;
                width: 100%;
                overflow: hidden;
            }
            
            .btn {
                padding: 0.65rem 1rem;
                font-size: 0.9rem;
                white-space: nowrap;
            }
            
            .secondary-actions {
                gap: 0.5rem;
                margin-top: 0.5rem;
                width: 100%;
            }
            
            .consent-section {
                padding: 0.75rem;
                margin: 0.75rem 0;
            }
            
            .consent-section label {
                font-size: 0.85rem;
            }
            
            .consent-section input[type="checkbox"] {
                width: 18px;
                height: 18px;
            }
        }

        /* Status Modal */
        .status-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
        }

        .modal-header h3 {
            margin: 0;
            color: #333;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s ease;
        }

        .modal-close:hover {
            background: #f0f0f0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .status-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            margin: 1rem 0;
            transition: border-color 0.3s ease;
        }

        .status-input:focus {
            outline: none;
            border-color: #ff5a5a;
        }

        .check-status-btn {
            background: #ff5a5a;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            width: 100%;
            transition: background 0.3s ease;
        }

        .check-status-btn:hover {
            background: #ff3333;
        }

        .status-result {
            margin-top: 1rem;
        }

        .status-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            border-left: 4px solid #ff5a5a;
        }

        .status-card h4 {
            margin: 0 0 0.5rem 0;
            color: #333;
        }

        .status-card p {
            margin: 0 0 0.5rem 0;
            color: #666;
        }

        .status-card small {
            color: #999;
        }

        .loading {
            color: #007bff;
            font-style: italic;
        }

        .error {
            color: #ff5a5a;
            font-weight: 500;
        }

        /* Remove Row Button */
        .remove-row-btn {
            background: #ff5a5a;
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
            margin: 0 auto;
        }

        .remove-row-btn:hover {
            background: #ff3333;
        }

        .remove-row-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* Center action column content */
        .participation-table td:last-child,
        .affiliation-table td:last-child,
        .participation-table th:last-child,
        .affiliation-table th:last-child {
            text-align: center;
            vertical-align: middle;
            width: 60px;
            padding: 0.5rem;
        }

        /* Center the dash in first rows */
        .participation-table tbody tr:first-child td:last-child,
        .affiliation-table tbody tr:first-child td:last-child {
            text-align: center;
            font-weight: bold;
            color: #666;
            font-size: 1.2rem;
        }
    </style>
    <style>
        .otp-modal-backdrop { display:none; position:fixed; inset:0; z-index:2000; background:rgba(35,24,24,.72); align-items:center; justify-content:center; padding:1rem; }
        .otp-modal-backdrop.is-open { display:flex; }
        .otp-modal { width:100%; max-width:440px; background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 24px 70px rgba(0,0,0,.3); animation:otpModalIn .22s ease-out; }
        @keyframes otpModalIn { from { opacity:0; transform:translateY(16px) scale(.98); } to { opacity:1; transform:none; } }
        .otp-modal-header { background:linear-gradient(135deg,#c93636,#ef6565); color:#fff; padding:1.35rem 1.5rem; }
        .otp-modal-close { float:right; border:0; background:rgba(255,255,255,.18); color:#fff; width:30px; height:30px; border-radius:50%; font-size:1.35rem; line-height:1; cursor:pointer; }
        .otp-modal-close:hover { background:rgba(255,255,255,.32); }
        .otp-modal-header h2 { margin:0; font-size:1.3rem; }
        .otp-modal-header p { margin:.35rem 0 0; font-size:.85rem; opacity:.9; }
        .otp-modal-body { padding:1.5rem; }
        .otp-modal-body p { color:#667085; font-size:.9rem; line-height:1.5; margin-top:0; }
        .otp-modal-input { width:100%; box-sizing:border-box; border:2px solid #e1e5e9; border-radius:10px; padding:.9rem; text-align:center; font-size:1.5rem; letter-spacing:.55rem; color:#243047; outline:none; }
        .otp-modal-input:focus { border-color:#c93636; box-shadow:0 0 0 3px rgba(201,54,54,.12); }
        .otp-modal-status { min-height:1.3rem; margin:.65rem 0; font-size:.82rem; color:#c93636; }
        .otp-modal-meta { display:flex; justify-content:space-between; gap:.75rem; color:#667085; font-size:.78rem; margin:.8rem 0 1.2rem; }
        .otp-modal-actions { display:flex; gap:.7rem; }
        .otp-modal-actions button { flex:1; border:0; border-radius:9px; padding:.8rem; font-weight:700; cursor:pointer; }
        .otp-verify-btn { background:#c93636; color:#fff; }
        .otp-resend-btn { background:#fff1f1; color:#a52d2d; border:1px solid #f1b6b6 !important; }
        .otp-modal-actions button:disabled { opacity:.5; cursor:not-allowed; }
        .otp-lock-message { background:#fff1f1; border:1px solid #f1b6b6; border-radius:9px; padding:.75rem; color:#8b1e1e; font-size:.85rem; line-height:1.45; }
        @media (max-width:480px) { .otp-modal-body { padding:1.2rem; } .otp-modal-actions { flex-direction:column; } }
    </style>
</head>
<body>
    <div class="performer-profile-container">
        <div class="performer-profile-card">
            <div class="form-header">
                <h1 class="form-title">PERFORMER'S PROFILE FORM</h1>
            </div>

            <div class="form-body">
                <form class="performer-form" id="performerForm" method="POST" action="performer-profile-form.php" enctype="multipart/form-data">
                    <!-- Type of Performance Section -->
                    <div class="form-section">
                        <h3 class="section-title">Type of Performance: <span class="instruction">(Include the name of Cultural Group you are interested in joining.)</span></h3>
                        <div class="checkbox-grid">
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="performing_arts">
                                <span class="checkmark"></span>
                                Performing Arts
                                <input type="text" id="performanceOther" class="inline-text" placeholder="Specify">
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="music">
                                <span class="checkmark"></span>
                                Music
                                <input type="text" class="inline-text" placeholder="Specify">
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="dance">
                                <span class="checkmark"></span>
                                Dance
                                <input type="text" class="inline-text" placeholder="Specify">
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="theater">
                                <span class="checkmark"></span>
                                Theater
                                <input type="text" class="inline-text" placeholder="Specify">
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="visual_arts">
                                <span class="checkmark"></span>
                                Visual Arts
                                <input type="text" class="inline-text" placeholder="Specify">
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="performance_type[]" value="literary_arts">
                                <span class="checkmark"></span>
                                Literary Arts
                                <input type="text" class="inline-text" placeholder="Specify">
                            </label>
                        </div>
                        <div class="privacy-note">
                            <p><strong>Pursuant to Republic Act No. 10173</strong> also known as the Data Privacy Act of 2012, the Batangas State University, the National Engineering University, recognizes the fundamental human right of privacy, of communication while ensuring the protection of personal data and/or stakeholders and ensure that all the information collected from them are all processed in accordance with the principles of transparency, legitimate purpose and proportionality mandated under the said Data Privacy Act.</p>
                        </div>
                        <div class="consent-section">
                            <h4>Consent of Data Subject</h4>
                            <div class="consent-options">
                                <label class="radio-item">
                                    <input type="radio" name="consent" value="yes" required>
                                    <span class="radiomark"></span>
                                    Yes
                                    <span class="consent-text">I hereby give my consent and hereby authorize Batangas State University, the National Engineering University to share, disclose, or transfer any personal information I have provided herein to any third party or affiliate as they may deem necessary for any legal purposes in compliance with the Data Privacy.</span>
                                </label>
                                <label class="radio-item">
                                    <input type="radio" name="consent" value="no" required>
                                    <span class="radiomark"></span>
                                    No
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">I. PERSONAL INFORMATION</h3>
                        <div class="photo-section">
                            <div class="photo-placeholder" id="photoPreview">
                                <span>2" x 2" ID Picture</span>
                            </div>
                            <input type="file" id="photoUpload" name="photo" accept="image/*" style="display: none;">
                            <button type="button" class="upload-photo-btn" onclick="document.getElementById('photoUpload').click()">
                                Upload Photo
                            </button>
                        </div>
                        <div class="form-grid">
                            <div class="form-row">
                                <div class="form-group half">
                                    <label>First Name: <span style="color: red;">*</span></label>
                                    <input type="text" name="first_name" required>
                                </div>
                                <div class="form-group half">
                                    <label>Middle Name:</label>
                                    <input type="text" name="middle_name">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Last Name: <span style="color: red;">*</span></label>
                                <input type="text" name="last_name" required>
                            </div>
                            <div class="form-group">
                                <label>Address:</label>
                                <input type="text" name="address" required>
                            </div>
                            <div class="form-group">
                                <label>Present Address:</label>
                                <input type="text" name="present_address">
                            </div>
                            <div class="form-row">
                                <div class="form-group half">
                                    <label>Date of Birth:</label>
                                    <input type="date" id="birthdate" name="date_of_birth" required>
                                </div>
                                <div class="form-group quarter">
                                    <label>Age:</label>
                                    <input type="number" id="age" name="age" min="1" max="100">
                                </div>
                                <div class="form-group quarter">
                                    <label>Gender:</label>
                                    <select name="gender" required>
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Place of Birth:</label>
                                <input type="text" name="place_of_birth">
                            </div>
                            <div class="form-group">
                                <label>Email:</label>
                                    <input type="email" id="emailAddress" name="email" required>
                            </div>
                            <div class="form-group">
                                <label>Contact Number:</label>
                                    <input type="tel" id="contactNumber" name="contact_number">
                            </div>
                            <div class="form-group">
                                <label>Father's Name:</label>
                                <input type="text" name="father_name">
                            </div>
                            <div class="form-group">
                                <label>Mother's Name:</label>
                                <input type="text" name="mother_name">
                            </div>
                            <div class="form-group">
                                <label>Guardian (if not living with Parents):</label>
                                <input type="text" name="guardian">
                            </div>
                            <div class="form-group">
                                <label>Contact Number (Parent/Guardian):</label>
                                <input type="tel" name="guardian_contact">
                            </div>
                        </div>
                    </div>

                    <!-- Educational Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">II. EDUCATIONAL INFORMATION</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Campus:</label>
                                <select id="campusSelect" name="campus" required>
                                    <option value="">Select Campus</option>
                                    <option value="Pablo Borbon">Pablo Borbon</option>
                                    <option value="Alangilan">Alangilan</option>
                                    <option value="Lipa">Lipa</option>
                                    <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                                    <option value="JPLPC Malvar">JPLPC Malvar</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <div class="form-group half">
                                    <label>Extension Campus/College:</label>
                                    <select id="collegeSelect" name="college" required disabled>
                                        <option value="">Select Extension Campus/College</option>
                                    </select>
                                </div>
                                <div class="form-group quarter">
                                    <label>SR Code:</label>
                                    <input type="text" name="sr_code">
                                </div>
                                <div class="form-group quarter">
                                    <label>Year Level:</label>
                                    <select name="year_level">
                                        <option value="">Select</option>
                                        <option value="1st">1st Year</option>
                                        <option value="2nd">2nd Year</option>
                                        <option value="3rd">3rd Year</option>
                                        <option value="4th">4th Year</option>
                                        <option value="5th">5th Year</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group half">
                                    <label>Program:</label>
                                    <input type="text" name="program">
                                </div>
                                <div class="form-group half">
                                    <label>Number of Units:</label>
                                    <div class="checkbox-row">
                                        <label class="checkbox-inline">
                                            <input type="checkbox" id="firstSemesterCheck" name="semester" value="first">
                                            First Semester
                                        </label>
                                        <input type="number" id="firstSemesterUnits" class="inline-units" name="first_semester_units" placeholder="Units" min="0" max="30" disabled>
                                        <label class="checkbox-inline">
                                            <input type="checkbox" id="secondSemesterCheck" name="semester" value="second">
                                            Second Semester
                                        </label>
                                        <input type="number" id="secondSemesterUnits" class="inline-units" name="second_semester_units" placeholder="Units" min="0" max="30" disabled>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Instructors:</label>
                                <div id="instructorsList">
                                    <div class="instructor-item">
                                        <input type="text" name="instructors[]" placeholder="Instructor Name" class="instructor-input">
                                        <button type="button" class="remove-instructor-btn" onclick="removeInstructor(this)" style="display: none;">Remove</button>
                                    </div>
                                </div>
                                <button type="button" class="add-instructor-btn" onclick="addInstructor()">+ Add Instructor</button>
                            </div>
                        </div>
                    </div>

                    <!-- Participation Section -->
                    <div class="form-section">
                        <h3 class="section-title">III. PARTICIPATION IN THE FIELD OF INTEREST / ACHIEVEMENTS</h3>
                        <div class="table-section">
                            <table class="participation-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Level<br><span class="sub-text">(Local, Regional, National, International)</span></th>
                                        <th>Rank (Place)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="participationTableBody">
                                    <tr>
                                        <td><input type="date" name="participation_date[]"></td>
                                        <td><input type="text" name="participation_event[]"></td>
                                        <td>
                                            <select name="participation_level[]">
                                                <option value="">Select</option>
                                                <option value="local">Local</option>
                                                <option value="regional">Regional</option>
                                                <option value="national">National</option>
                                                <option value="international">International</option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="participation_rank[]"></td>
                                        <td>-</td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="add-row-btn" onclick="addParticipationRow()">+ Add Row</button>
                        </div>
                    </div>

                    <!-- Competition Section (NEW) -->
                    <div class="form-section">
                        <h3 class="section-title">IV. COMPETITION PARTICIPATED</h3>
                        <div class="table-section">
                            <table class="competition-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Level<br><span class="sub-text">(Local, Regional, National, International)</span></th>
                                        <th>Rank (Place)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="competitionTableBody">
                                    <tr>
                                        <td><input type="date" name="competition_date[]"></td>
                                        <td><input type="text" name="competition_event[]"></td>
                                        <td>
                                            <select name="competition_level[]">
                                                <option value="">Select</option>
                                                <option value="local">Local</option>
                                                <option value="regional">Regional</option>
                                                <option value="national">National</option>
                                                <option value="international">International</option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="competition_rank[]"></td>
                                        <td>-</td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="add-row-btn" onclick="addCompetitionRow()">+ Add Row</button>
                        </div>
                    </div>

                    <!-- Affiliation Section -->
                    <div class="form-section">
                        <h3 class="section-title">V. AFFILIATION TO ORGANIZATIONS</h3>
                        <div class="table-section">
                            <table class="affiliation-table">
                                <thead>
                                    <tr>
                                        <th>Position</th>
                                        <th>Name of Organization</th>
                                        <th>Inclusive Years</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="affiliationTableBody">
                                    <tr>
                                        <td><input type="text" name="affiliation_position[]"></td>
                                        <td><input type="text" name="affiliation_organization[]"></td>
                                        <td><input type="text" name="affiliation_years[]" placeholder="e.g., 2020-2023"></td>
                                        <td>-</td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="add-row-btn" onclick="addAffiliationRow()">+ Add Row</button>
                        </div>
                    </div>

                    <!-- Certification Section -->
                    <div class="form-section">
                        <div class="certification">
                            <label class="checkbox-item full-width">
                                <input type="checkbox" id="certificationCheck" name="certification" required>
                                <span class="checkmark"></span>
                                I hereby certify that all information in this form is true and correct. Any misrepresentation of facts will render this invalid and immediately disqualifies my membership to the cultural group.
                            </label>
                        </div>
                        
                        <div class="signature-section">
                            <div class="signature-field">
                                <label>Signature of Performer/Member</label>
                                <canvas id="signatureCanvas" class="signature-canvas" width="400" height="150"></canvas>
                                <div class="signature-controls">
                                    <button type="button" class="clear-signature-btn" onclick="clearSignature()">Clear Signature</button>
                                </div>
                            </div>
                            <div class="date-field">
                                <label>Date:</label>
                                <input type="date" name="signature_date">
                            </div>
                        </div>

                        <div class="attachment-note">
                            <p><strong>*Required Attachments:</strong> Certified True Copy of Certificates and Recognitions</p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="cancel-btn">Cancel</button>
                        <button type="submit" class="submit-btn">Submit Form</button>
                    </div>
                    
                </form>
            </div>
        </div>
    </div>

    <div class="otp-modal-backdrop" id="otpModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="otpModalTitle">
        <div class="otp-modal">
            <div class="otp-modal-header"><button type="button" class="otp-modal-close" id="otpModalClose" aria-label="Close verification">&times;</button><h2 id="otpModalTitle">Verify your email</h2><p>OCA Notification System</p></div>
            <div class="otp-modal-body">
                <p>We sent a 6-digit code to <strong id="otpModalEmail"></strong>. Enter it below to submit your application.</p>
                <input class="otp-modal-input" id="otpModalInput" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-label="6-digit verification code">
                <div class="otp-modal-status" id="otpModalStatus" role="status"></div>
                <div class="otp-modal-meta"><span>Expires in <strong id="otpExpiry">5:00</strong></span><span>Resends <strong id="otpResendCount">0/5</strong></span></div>
                <div class="otp-modal-actions"><button type="button" class="otp-verify-btn" id="otpVerifyButton">Verify code</button><button type="button" class="otp-resend-btn" id="otpResendButton" disabled>Resend in 60s</button></div>
            </div>
        </div>
    </div>

    <script>
        function showOtpModal(email, initialState, verifyOtp) {
            const backdrop = document.getElementById('otpModalBackdrop');
            const input = document.getElementById('otpModalInput');
            const verifyButton = document.getElementById('otpVerifyButton');
            const resendButton = document.getElementById('otpResendButton');
            const closeButton = document.getElementById('otpModalClose');
            const status = document.getElementById('otpModalStatus');
            const expiry = document.getElementById('otpExpiry');
            const resendCount = document.getElementById('otpResendCount');
            document.getElementById('otpModalEmail').textContent = email;
            backdrop.classList.add('is-open');
            input.value = '';
            input.focus();
            let expiresAt = Number(initialState.expires_at) * 1000;
            let resendAt = Number(initialState.resend_at) * 1000;
            let count = Number(initialState.resend_count || 0);
            let locked = false;
            resendCount.textContent = count + '/5';

            return new Promise((resolve) => {
                let finished = false;
                const close = (value) => {
                    if (finished) return;
                    finished = true;
                    clearInterval(timer);
                    backdrop.classList.remove('is-open');
                    verifyButton.onclick = null;
                    resendButton.onclick = null;
                    closeButton.onclick = null;
                    resolve(value);
                };
                const update = () => {
                    const now = Date.now();
                    const expirySeconds = Math.max(0, Math.ceil((expiresAt - now) / 1000));
                    expiry.textContent = Math.floor(expirySeconds / 60) + ':' + String(expirySeconds % 60).padStart(2, '0');
                    if (expirySeconds === 0 && !locked) {
                        status.textContent = 'This code expired. Request a new code.';
                        verifyButton.disabled = true;
                    }
                    const resendSeconds = Math.max(0, Math.ceil((resendAt - now) / 1000));
                    resendButton.disabled = resendSeconds > 0 || locked || count >= 5;
                    resendButton.textContent = resendSeconds > 0 ? 'Resend in ' + resendSeconds + 's' : (locked ? 'Resend locked' : 'Resend code');
                };
                const timer = setInterval(update, 1000);
                update();
                verifyButton.onclick = async () => {
                    const value = input.value.trim();
                    if (!/^\d{6}$/.test(value)) {
                        status.textContent = 'Enter exactly 6 numbers.';
                        input.focus();
                        return;
                    }
                    verifyButton.disabled = true;
                    resendButton.disabled = true;
                    status.textContent = 'Checking code...';
                    try {
                        const result = await verifyOtp(value);
                        if (!result.success) throw new Error(result.message || 'Incorrect code. Try again.');
                        close(value);
                    } catch (error) {
                        status.textContent = error.message || 'Incorrect code. Try again.';
                        input.value = '';
                        input.focus();
                        verifyButton.disabled = false;
                        update();
                    }
                };
                closeButton.onclick = () => close(null);
                resendButton.onclick = async () => {
                    resendButton.disabled = true;
                    resendButton.textContent = 'Sending...';
                    status.textContent = '';
                    try {
                        const request = new FormData();
                        request.append('otp_action', 'resend');
                        request.append('email', email);
                        const response = await fetch('performer-profile-form.php', { method:'POST', body:request, headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'} });
                        const result = await response.json();
                        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to resend the code.');
                        expiresAt = Number(result.expires_at) * 1000;
                        resendAt = Number(result.resend_at) * 1000;
                        count = Number(result.resend_count || count + 1);
                        resendCount.textContent = count + '/5';
                        input.value = '';
                        input.focus();
                        if (result.locked_until) {
                            locked = true;
                            verifyButton.disabled = true;
                            status.innerHTML = '<div class="otp-lock-message">Too many resend requests. This email is locked for 2 hours. You cannot continue until the lock expires.</div>';
                        } else {
                            status.textContent = 'A new code was sent.';
                        }
                        update();
                    } catch (error) {
                        status.textContent = error.message;
                        update();
                    }
                };
            });
        }

        class PerformerProfileForm {
            constructor() {
                console.log('PerformerProfileForm constructor called');
                
                try {
                    this.form = document.getElementById('performerForm');
                    console.log('Form element found:', this.form);
                    
                    this.participationTable = document.querySelector('.participation-table tbody');
                    this.competitionTable = document.querySelector('.competition-table tbody');
                    this.affiliationTable = document.querySelector('.affiliation-table tbody');
                    this.submitBtn = document.querySelector('.submit-btn');
                    this.cancelBtn = document.querySelector('.cancel-btn');
                    this.isSubmitting = false;
                    
                    // Campus and College selects
                    this.campusSelect = document.getElementById('campusSelect');
                    this.collegeSelect = document.getElementById('collegeSelect');
                    
                    // College mapping by campus
                    this.collegesByCampus = {
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
                    
                    // Signature canvas setup
                    this.canvas = document.getElementById('signatureCanvas');
                    console.log('Canvas element found:', this.canvas);
                    
                    this.ctx = this.canvas ? this.canvas.getContext('2d') : null;
                    console.log('Canvas context:', this.ctx);
                    
                    this.isDrawing = false;
                    this.hasSignature = false;
                    
                    console.log('About to call initializeForm...');
                    this.initializeForm();
                    console.log('PerformerProfileForm constructor completed successfully');
                    
                } catch (error) {
                    console.error('Error in PerformerProfileForm constructor:', error);
                    throw error;
                }
            }

            initializeForm() {
                console.log('initializeForm() called');
                
                try {
                    console.log('Calling bindEvents...');
                    this.bindEvents();
                    this.setupCampusCollegeFilter();
                    
                    console.log('initializeForm() completed successfully');
                } catch (error) {
                    console.error('Error in initializeForm:', error);
                    throw error;
                }
            }

            setupCampusCollegeFilter() {
                if (!this.campusSelect || !this.collegeSelect) {
                    console.warn('Campus or College select not found');
                    return;
                }
                
                // Populate college dropdown when campus is selected
                this.campusSelect.addEventListener('change', () => {
                    const selectedCampus = this.campusSelect.value;
                    this.populateCollegeDropdown(selectedCampus);
                });
            }

            populateCollegeDropdown(campus) {
                // Clear existing options except the default one
                this.collegeSelect.innerHTML = '<option value="">Select Extension Campus/College</option>';
                
                if (campus && this.collegesByCampus[campus]) {
                    // Add colleges for the selected campus
                    this.collegesByCampus[campus].forEach(college => {
                        const option = document.createElement('option');
                        option.value = college;
                        option.textContent = college;
                        this.collegeSelect.appendChild(option);
                    });
                    
                    // Enable the college dropdown
                    this.collegeSelect.disabled = false;
                } else {
                    // Disable if no campus selected
                    this.collegeSelect.disabled = true;
                }
                
                // Reset the selected value
                this.collegeSelect.value = '';
            }

            bindEvents() {
                // Form submission
                this.form.addEventListener('submit', (e) => this.handleSubmit(e));
                
                // Cancel button
                this.cancelBtn.addEventListener('click', (e) => this.handleCancel(e));
                
                // Dynamic row addition - these buttons use onclick attributes in HTML, so we don't need to add listeners here
                
                // Performance type validation
                this.setupPerformanceTypeValidation();
                
                // Photo section validation
                this.setupPhotoSection();
                
                // Real-time validation for required fields
                this.setupRealTimeValidation();
                
                // Signature canvas setup
                this.setupSignatureCanvas();
                
                // Semester selection
                this.setupSemesterSelection();
            }

            setupSemesterSelection() {
                const firstSemCheck = document.getElementById('firstSemesterCheck');
                const secondSemCheck = document.getElementById('secondSemesterCheck');
                const firstSemUnits = document.getElementById('firstSemesterUnits');
                const secondSemUnits = document.getElementById('secondSemesterUnits');
                
                if (!firstSemCheck || !secondSemCheck || !firstSemUnits || !secondSemUnits) {
                    console.warn('Semester selection elements not found');
                    return;
                }
                
                firstSemCheck.addEventListener('change', () => {
                    if (firstSemCheck.checked) {
                        // Enable first semester units, disable and clear second semester
                        firstSemUnits.disabled = false;
                        secondSemCheck.checked = false;
                        secondSemUnits.disabled = true;
                        secondSemUnits.value = '';
                    } else {
                        // If unchecked, disable and clear first semester units
                        firstSemUnits.disabled = true;
                        firstSemUnits.value = '';
                    }
                });
                
                secondSemCheck.addEventListener('change', () => {
                    if (secondSemCheck.checked) {
                        // Enable second semester units, disable and clear first semester
                        secondSemUnits.disabled = false;
                        firstSemCheck.checked = false;
                        firstSemUnits.disabled = true;
                        firstSemUnits.value = '';
                    } else {
                        // If unchecked, disable and clear second semester units
                        secondSemUnits.disabled = true;
                        secondSemUnits.value = '';
                    }
                });
            }

            setupPerformanceTypeValidation() {
                const performanceCheckboxes = document.querySelectorAll('input[name="performance_type[]"]');
                const otherInput = document.getElementById('performanceOther');
                
                performanceCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', () => {
                        this.validatePerformanceTypes();
                        
                        // Show/hide other input field
                        if (checkbox.value === 'others' && checkbox.checked) {
                            otherInput.style.display = 'inline';
                            otherInput.required = true;
                        } else if (checkbox.value === 'others' && !checkbox.checked) {
                            otherInput.style.display = 'none';
                            otherInput.required = false;
                            otherInput.value = '';
                        }
                    });
                });
            }

            validatePerformanceTypes() {
                const performanceCheckboxes = document.querySelectorAll('input[name="performance_type[]"]');
                const checkedBoxes = document.querySelectorAll('input[name="performance_type[]"]:checked');
                const errorDiv = document.getElementById('performanceTypeError');
                
                if (checkedBoxes.length === 0) {
                    this.showFieldError(performanceCheckboxes[0], 'Please select at least one type of performance.');
                    return false;
                } else {
                    this.clearFieldError(performanceCheckboxes[0]);
                    return true;
                }
            }

            setupPhotoSection() {
                const photoUpload = document.getElementById('photoUpload');
                const photoPlaceholder = document.getElementById('photoPreview');
                
                if (photoUpload && photoPlaceholder) {
                    photoUpload.addEventListener('change', (e) => {
                        const file = e.target.files[0];
                        
                        if (file) {
                            // Check if file is an image
                            if (!file.type.startsWith('image/')) {
                                this.showAlert('Please select a valid image file.', 'error');
                                photoUpload.value = '';
                                return;
                            }
                            
                            // Check file size (15MB = 15 * 1024 * 1024 bytes)
                            const maxSize = 15 * 1024 * 1024; // 15MB in bytes
                            if (file.size > maxSize) {
                                this.showAlert('Image file size must be less than 15MB.', 'error');
                                photoUpload.value = '';
                                return;
                            }
                            
                            // Show preview
                            const reader = new FileReader();
                            reader.onload = (e) => {
                                photoPlaceholder.innerHTML = `<img src="${e.target.result}" alt="Profile Photo">`;
                            };
                            reader.readAsDataURL(file);
                        }
                    });
                }
            }

            setupRealTimeValidation() {
                // Name field validation (no numbers or special characters)
                const nameFields = ['first_name', 'middle_name', 'last_name', 'father_name', 'mother_name', 'guardian'];
                nameFields.forEach(fieldName => {
                    const field = document.querySelector(`input[name="${fieldName}"]`);
                    if (field) {
                        field.addEventListener('input', () => this.validateNameField(field));
                        field.addEventListener('blur', () => this.validateNameField(field));
                    }
                });
                
                // SR Code validation
                const srCodeField = document.querySelector('input[name="sr_code"]');
                if (srCodeField) {
                    let srTimeout;
                    srCodeField.addEventListener('input', () => {
                        if (srTimeout) clearTimeout(srTimeout);
                        srTimeout = setTimeout(() => {
                            this.validateSRCode(srCodeField);
                        }, 300);
                    });
                    srCodeField.addEventListener('blur', () => {
                        if (srTimeout) clearTimeout(srTimeout);
                        if (this.validateSRCode(srCodeField)) {
                            this.checkDuplicateSRCode(srCodeField);
                        }
                    });
                }
                
                // Email validation
                const emailField = document.getElementById('emailAddress');
                if (emailField) {
                    let emailTimeout;
                    emailField.addEventListener('input', () => {
                        // Clear previous timeout
                        if (emailTimeout) clearTimeout(emailTimeout);
                        
                        // Only clear error if field is empty
                        if (!emailField.value.trim()) {
                            this.clearFieldError(emailField);
                        } else {
                            // Debounce validation to avoid flickering
                            emailTimeout = setTimeout(() => {
                                this.validateEmailFormat(emailField);
                            }, 500);
                        }
                    });
                    emailField.addEventListener('blur', () => {
                        if (emailTimeout) clearTimeout(emailTimeout);
                        this.validateEmailComplete(emailField);
                    });
                }
                
                // Handle other required fields that don't have specific validation
                const requiredFields = this.form.querySelectorAll('input[required], select[required]');
                const specialFields = ['first_name', 'middle_name', 'last_name', 'father_name', 'mother_name', 'guardian', 'sr_code', 'email'];
                
                requiredFields.forEach(field => {
                    const fieldName = field.name || field.id;
                    if (!specialFields.includes(fieldName) && !specialFields.includes(field.getAttribute('name'))) {
                        field.addEventListener('blur', () => this.validateField(field));
                        field.addEventListener('input', () => {
                            // Only clear error if field has content or is empty
                            if (field.value.trim()) {
                                this.clearFieldError(field);
                            }
                        });
                    }
                });
                
                // Contact number validation
                const contactField = document.getElementById('contactNumber');
                if (contactField) {
                    contactField.addEventListener('input', () => this.formatContactNumber(contactField));
                }
                
                const guardianContactField = document.querySelector('input[name="guardian_contact"]');
                if (guardianContactField) {
                    guardianContactField.addEventListener('input', () => this.formatContactNumber(guardianContactField));
                }
                
                // Age calculation and validation
                const birthdateField = document.getElementById('birthdate');
                const ageField = document.getElementById('age');
                if (birthdateField && ageField) {
                    birthdateField.addEventListener('change', () => {
                        const age = this.calculateAge(birthdateField.value);
                        ageField.value = age;
                        this.validateAge(ageField);
                    });
                    ageField.addEventListener('input', () => this.validateAge(ageField));
                }
                
                // Program field validation (no numbers at start)
                const programField = document.querySelector('input[name="program"]');
                if (programField) {
                    programField.addEventListener('input', () => this.validateProgramField(programField));
                }
                
                // Units validation (numbers only)
                const unitsFields = ['first_semester_units', 'second_semester_units'];
                unitsFields.forEach(fieldName => {
                    const field = document.getElementById(fieldName.replace('_', '').replace('semester', 'Semester').replace('units', 'Units'));
                    if (field) {
                        field.addEventListener('input', () => this.validateUnitsField(field));
                    }
                });
            }

            setupSignatureCanvas() {
                console.log('Setting up signature canvas...'); // Debug log
                
                if (!this.canvas) {
                    console.error('Canvas element not found!');
                    return;
                }
                
                if (!this.ctx) {
                    console.error('Canvas context not available!');
                    return;
                }
                
                // Set canvas size properly
                this.canvas.width = 400;
                this.canvas.height = 150;
                
                // Set canvas background
                this.ctx.fillStyle = '#ffffff';
                this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
                
                // Set drawing properties
                this.ctx.strokeStyle = '#000000';
                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.ctx.globalCompositeOperation = 'source-over';
                
                console.log('Canvas initialized:', this.canvas.width, 'x', this.canvas.height);
                
                // Mouse events
                this.canvas.addEventListener('mousedown', (e) => {
                    console.log('Mouse down event triggered');
                    this.startDrawing(e);
                });
                this.canvas.addEventListener('mousemove', (e) => {
                    if (this.isDrawing) console.log('Mouse move while drawing');
                    this.draw(e);
                });
                this.canvas.addEventListener('mouseup', () => {
                    console.log('Mouse up event triggered');
                    this.stopDrawing();
                });
                this.canvas.addEventListener('mouseout', () => {
                    console.log('Mouse out event triggered');
                    this.stopDrawing();
                });
                
                console.log('Mouse event listeners attached');
                
                // Touch events for mobile
                this.canvas.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    const touch = e.touches[0];
                    const mouseEvent = new MouseEvent('mousedown', {
                        clientX: touch.clientX,
                        clientY: touch.clientY
                    });
                    this.canvas.dispatchEvent(mouseEvent);
                });
                
                this.canvas.addEventListener('touchmove', (e) => {
                    e.preventDefault();
                    const touch = e.touches[0];
                    const mouseEvent = new MouseEvent('mousemove', {
                        clientX: touch.clientX,
                        clientY: touch.clientY
                    });
                    this.canvas.dispatchEvent(mouseEvent);
                });
                
                this.canvas.addEventListener('touchend', (e) => {
                    e.preventDefault();
                    const mouseEvent = new MouseEvent('mouseup', {});
                    this.canvas.dispatchEvent(mouseEvent);
                });
            }

            startDrawing(e) {
                this.isDrawing = true;
                const rect = this.canvas.getBoundingClientRect();
                const scaleX = this.canvas.width / rect.width;
                const scaleY = this.canvas.height / rect.height;
                const x = (e.clientX - rect.left) * scaleX;
                const y = (e.clientY - rect.top) * scaleY;
                
                // Set drawing properties
                this.ctx.strokeStyle = '#000000';
                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                
                // Start new path and set starting point
                this.ctx.beginPath();
                this.ctx.moveTo(x, y);
                
                this.hasSignature = true;
                console.log('Started drawing at:', x, y); // Debug log
            }

            draw(e) {
                if (!this.isDrawing) return;
                
                const rect = this.canvas.getBoundingClientRect();
                const scaleX = this.canvas.width / rect.width;
                const scaleY = this.canvas.height / rect.height;
                const x = (e.clientX - rect.left) * scaleX;
                const y = (e.clientY - rect.top) * scaleY;
                
                // Draw line to new position
                this.ctx.lineTo(x, y);
                this.ctx.stroke();
                
                // Begin new path for next segment to avoid redrawing entire path
                this.ctx.beginPath();
                this.ctx.moveTo(x, y);
                
                console.log('Drawing to:', x, y); // Debug log
            }

            stopDrawing() {
                if (this.isDrawing) {
                    this.isDrawing = false;
                    this.ctx.beginPath(); // End current path
                    console.log('Stopped drawing'); // Debug log
                }
            }

            clearSignature() {
                console.log('clearSignature() called'); // Debug log
                
                if (!this.canvas) {
                    console.error('Canvas not found in clearSignature');
                    return;
                }
                
                if (!this.ctx) {
                    console.error('Canvas context not found in clearSignature');
                    return;
                }
                
                console.log('Clearing signature canvas...'); // Debug log
                
                // Clear the canvas
                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                
                // Set white background
                this.ctx.fillStyle = '#ffffff';
                this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
                
                this.hasSignature = false;
                
                // Reset drawing properties after clearing
                this.ctx.strokeStyle = '#000000';
                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                
                console.log('Signature cleared successfully'); // Debug log
            }

            getSignatureData() {
                if (!this.hasSignature) return null;
                return this.canvas.toDataURL();
            }

            addParticipationRow() {
                const tbody = this.participationTable;
                
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input type="date" name="participation_date[]"></td>
                    <td><input type="text" name="participation_event[]"></td>
                    <td>
                        <select name="participation_level[]">
                            <option value="">Select</option>
                            <option value="local">Local</option>
                            <option value="regional">Regional</option>
                            <option value="national">National</option>
                            <option value="international">International</option>
                        </select>
                    </td>
                    <td><input type="text" name="participation_rank[]"></td>
                    <td><button type="button" class="remove-row-btn" onclick="removeParticipationRow(this)" title="Remove row">−</button></td>
                `;
                
                tbody.appendChild(row);
            }

            addCompetitionRow() {
                const tbody = this.competitionTable;
                
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input type="date" name="competition_date[]"></td>
                    <td><input type="text" name="competition_event[]"></td>
                    <td>
                        <select name="competition_level[]">
                            <option value="">Select</option>
                            <option value="local">Local</option>
                            <option value="regional">Regional</option>
                            <option value="national">National</option>
                            <option value="international">International</option>
                        </select>
                    </td>
                    <td><input type="text" name="competition_rank[]"></td>
                    <td><button type="button" class="remove-row-btn" onclick="removeCompetitionRow(this)" title="Remove row">−</button></td>
                `;
                
                tbody.appendChild(row);
            }

            addAffiliationRow() {
                const tbody = this.affiliationTable;
                
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input type="text" name="affiliation_position[]"></td>
                    <td><input type="text" name="affiliation_organization[]"></td>
                    <td><input type="text" name="affiliation_years[]" placeholder="e.g., 2020-2023"></td>
                    <td><button type="button" class="remove-row-btn" onclick="removeAffiliationRow(this)" title="Remove row"> −</button></td>
                `;
                
                tbody.appendChild(row);
            }

            calculateAge(birthdate) {
                if (!birthdate) return '';
                
                const birth = new Date(birthdate);
                const today = new Date();
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                
                return age;
            }

            formatContactNumber(field) {
                let value = field.value.replace(/\D/g, ''); // Remove non-digits
                
                // Limit to 11 digits for Philippine mobile numbers
                if (value.length > 11) {
                    value = value.slice(0, 11);
                }
                
                // Validate Philippine mobile number format
                if (value.length > 0) {
                    // Should start with 09 for mobile or area codes for landline
                    if (value.length >= 2 && !value.startsWith('09') && !value.startsWith('02') && !value.startsWith('03')) {
                        this.showFieldError(field, 'Philippine numbers should start with 09 (mobile) or area codes like 02, 03 (landline).');
                    } else if (value.length === 11 && value.startsWith('09')) {
                        this.clearFieldError(field);
                    } else if (value.length > 0 && value.length < 11 && value.startsWith('09')) {
                        // Still typing, don't show error yet
                        this.clearFieldError(field);
                    }
                }
                
                // Format as Philippine mobile number
                if (value.length >= 4) {
                    value = value.replace(/(\d{4})(\d{0,3})(\d{0,4})/, (match, p1, p2, p3) => {
                        let formatted = p1;
                        if (p2) formatted += '-' + p2;
                        if (p3) formatted += '-' + p3;
                        return formatted;
                    });
                }
                
                field.value = value;
            }

            validateField(field) {
                const value = field.value.trim();
                
                if (field.hasAttribute('required') && !value) {
                    this.showFieldError(field, `${this.getFieldLabel(field)} is required.`);
                    return false;
                }
                
                this.clearFieldError(field);
                return true;
            }

            validateNameField(field) {
                const value = field.value.trim();
                const nameRegex = /^[a-zA-ZÑñ\s.'\-]*$/; // Allow letters, spaces, periods, apostrophes, hyphens, and enye
                
                if (value && !nameRegex.test(value)) {
                    this.showFieldError(field, 'Name should only contain letters, spaces, and common punctuation (. \' -).');
                    return false;
                }
                
                // Check for numbers
                if (value && /\d/.test(value)) {
                    this.showFieldError(field, 'Numbers are not allowed in name fields.');
                    return false;
                }
                
                this.clearFieldError(field);
                return true;
            }

            validateSRCode(field) {
                const value = field.value.trim();
                // SR Code format: typically 2 digits, dash, 4 digits (e.g., 22-1234)
                const srCodeRegex = /^\d{2}-\d{5}$/;
                
                if (value && !srCodeRegex.test(value)) {
                    // Auto-format if it's just numbers
                    if (/^\d{6}$/.test(value)) {
                        field.value = value.substring(0, 2) + '-' + value.substring(2);
                        this.clearFieldError(field);
                        return true;
                    } else {
                        this.showFieldError(field, 'SR Code format should be XX-XXXXX (e.g., 22-12345).');
                        return false;
                    }
                }
                
                this.clearFieldError(field);
                return true;
            }

            async checkDuplicateSRCode(field) {
                const srCode = field.value.trim();
                if (!srCode) return true;
                
                try {
                    const response = await fetch('check-duplicate.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=check_sr_code&sr_code=${encodeURIComponent(srCode)}`
                    });
                    
                    const result = await response.json();
                    
                    if (result.exists) {
                        this.showFieldError(field, 'This SR Code is already registered. Please check your SR Code.');
                        return false;
                    } else {
                        // Only clear error if there's no format error
                        if (this.validateSRCode(field)) {
                            this.clearFieldError(field);
                        }
                        return true;
                    }
                } catch (error) {
                    console.warn('Could not check SR Code duplication:', error);
                    return true; // Continue if check fails
                }
            }

            validateEmailFormat(field) {
                const email = field.value.trim();
                if (!email) return true;
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                if (!emailRegex.test(email)) {
                    this.showFieldError(field, 'Please enter a valid email address.');
                    return false;
                }
                
                // Check if it's a BatStateU email
                if (!/@g\.batstate-u\.edu\.ph$/i.test(email) && email.toLowerCase() !== 'spamdummy11@gmail.com') {
                    this.showFieldError(field, 'Please use your official BatStateU email address (@g.batstate-u.edu.ph).');
                    return false;
                }
                
                return true;
            }

            async validateEmailComplete(field) {
                // First check format
                if (!this.validateEmailFormat(field)) {
                    return false;
                }
                
                // Then check for duplicates if format is valid
                const email = field.value.trim();
                if (email) {
                    return await this.checkDuplicateEmail(field);
                }
                
                return true;
            }

            validateEmail(field) {
                // Legacy method for form validation - just check format
                return this.validateEmailFormat(field);
            }

            async checkDuplicateEmail(field) {
                const email = field.value.trim();
                if (!email) return true;
                
                try {
                    const response = await fetch('check-duplicate.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=check_email&email=${encodeURIComponent(email)}`
                    });
                    
                    const result = await response.json();
                    
                    if (result.exists) {
                        this.showFieldError(field, 'This email address is already registered. Please use a different email.');
                        return false;
                    } else {
                        // Only clear error if there's no format error
                        if (this.validateEmailFormat(field)) {
                            this.clearFieldError(field);
                        }
                        return true;
                    }
                } catch (error) {
                    console.warn('Could not check email duplication:', error);
                    return true; // Continue if check fails
                }
            }

            validateAge(field) {
                const age = parseInt(field.value);
                
                if (field.value && (isNaN(age) || age < 15 || age > 50)) {
                    this.showFieldError(field, 'Please enter a valid age between 15 and 50.');
                    return false;
                }
                
                this.clearFieldError(field);
                return true;
            }

            validateProgramField(field) {
                const value = field.value.trim();
                
                // Check if it starts with a number
                if (value && /^\d/.test(value)) {
                    this.showFieldError(field, 'Program name should not start with a number.');
                    return false;
                }
                
                this.clearFieldError(field);
                return true;
            }

            validateUnitsField(field) {
                const value = field.value;
                
                // Only allow numbers
                if (value && !/^\d*$/.test(value)) {
                    field.value = value.replace(/\D/g, '');
                    this.showFieldError(field, 'Units field should only contain numbers.');
                    return false;
                }
                
                const units = parseInt(value);
                if (value && (units < 1 || units > 30)) {
                    this.showFieldError(field, 'Units should be between 1 and 30.');
                    return false;
                }
                
                this.clearFieldError(field);
                return true;
            }

            validateConsent() {
                const consentRadios = document.querySelectorAll('input[name="consent"]');
                const checkedConsent = document.querySelector('input[name="consent"]:checked');
                
                if (!checkedConsent) {
                    this.showFieldError(consentRadios[0], 'Please select your consent for data privacy.');
                    return false;
                }
                
                if (checkedConsent.value === 'no') {
                    this.showAlert('You must consent to data processing to submit this form.', 'error');
                    return false;
                }
                
                this.clearFieldError(consentRadios[0]);
                return true;
            }

            validateCertification() {
                const certificationCheck = document.getElementById('certificationCheck');
                
                if (!certificationCheck.checked) {
                    this.showFieldError(certificationCheck, 'Please certify that all information provided is true and correct.');
                    return false;
                }
                
                this.clearFieldError(certificationCheck);
                return true;
            }

            async validateForm() {
                console.log('Starting form validation...'); // Debug log
                let isValid = true;
                
                // Validate performance types
                console.log('Validating performance types...'); // Debug log
                if (!this.validatePerformanceTypes()) {
                    console.log('Performance types validation failed'); // Debug log
                    isValid = false;
                }
                
                // Validate required fields
                console.log('Validating required fields...'); // Debug log
                const requiredFields = this.form.querySelectorAll('input[required], select[required]');
                console.log('Found required fields:', requiredFields.length); // Debug log
                requiredFields.forEach(field => {
                    if (!this.validateField(field)) {
                        console.log('Required field validation failed:', field.name || field.id); // Debug log
                        isValid = false;
                    }
                });
                
                // Validate name fields
                console.log('Validating name fields...'); // Debug log
                const nameFields = ['first_name', 'middle_name', 'last_name', 'father_name', 'mother_name', 'guardian'];
                nameFields.forEach(fieldName => {
                    const field = document.querySelector(`input[name=\"${fieldName}\"]`);
                    if (field && field.value && !this.validateNameField(field)) {
                        console.log('Name field validation failed:', fieldName); // Debug log
                        isValid = false;
                    }
                });
                
                // Validate SR Code format and duplicates
                console.log('Validating SR Code...'); // Debug log
                const srCodeField = document.querySelector('input[name="sr_code"]');
                if (srCodeField && srCodeField.value) {
                    if (!this.validateSRCode(srCodeField)) {
                        console.log('SR Code format validation failed'); // Debug log
                        isValid = false;
                    } else {
                        // Check for duplicates
                        const isDuplicate = await this.checkDuplicateSRCode(srCodeField);
                        if (!isDuplicate) {
                            console.log('SR Code duplicate validation failed'); // Debug log
                            isValid = false;
                        }
                    }
                }
                
                // Validate email format and duplicates
                console.log('Validating email...'); // Debug log
                const emailField = document.getElementById('emailAddress');
                if (emailField && emailField.value) {
                    if (!this.validateEmail(emailField)) {
                        console.log('Email format validation failed'); // Debug log
                        isValid = false;
                    } else {
                        // Check for duplicates
                        const isDuplicate = await this.checkDuplicateEmail(emailField);
                        if (!isDuplicate) {
                            console.log('Email duplicate validation failed'); // Debug log
                            isValid = false;
                        }
                    }
                }
                
                // Validate age
                console.log('Validating age...'); // Debug log
                const ageField = document.getElementById('age');
                if (ageField && ageField.value && !this.validateAge(ageField)) {
                    console.log('Age validation failed'); // Debug log
                    isValid = false;
                }
                
                // Validate program field
                const programField = document.querySelector('input[name="program"]');
                if (programField && programField.value && !this.validateProgramField(programField)) {
                    console.log('Program validation failed'); // Debug log
                    isValid = false;
                }
                
                // Validate units fields
                const unitsFields = ['firstSemesterUnits', 'secondSemesterUnits'];
                unitsFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field && field.value && !this.validateUnitsField(field)) {
                        console.log('Units validation failed:', fieldId); // Debug log
                        isValid = false;
                    }
                });
                
                // Validate contact numbers
                const contactFields = [document.getElementById('contactNumber'), document.querySelector('input[name="guardian_contact"]')];
                contactFields.forEach(field => {
                    if (field && field.value) {
                        const value = field.value.replace(/\D/g, '');
                        if (value.length > 0 && value.length !== 11) {
                            this.showFieldError(field, 'Contact number must be exactly 11 digits.');
                            isValid = false;
                        } else if (value.length === 11 && !value.startsWith('09')) {
                            this.showFieldError(field, 'Mobile number should start with 09.');
                            isValid = false;
                        }
                    }
                });
                
                // Validate consent
                console.log('Validating consent...'); // Debug log
                if (!this.validateConsent()) {
                    console.log('Consent validation failed'); // Debug log
                    isValid = false;
                }
                
                // Validate certification
                console.log('Validating certification...'); // Debug log
                if (!this.validateCertification()) {
                    console.log('Certification validation failed'); // Debug log
                    isValid = false;
                }
                
                console.log('Form validation result:', isValid); // Debug log
                return isValid;
            }

            showFieldError(field, message) {
                this.clearFieldError(field);
                
                const errorDiv = document.createElement('div');
                errorDiv.className = 'field-error';
                errorDiv.textContent = message;
                
                // Find the best place to insert the error
                const parent = field.closest('.form-group') || field.closest('.checkbox-item') || field.parentNode;
                parent.appendChild(errorDiv);
                
                // Add error styling to field
                field.style.borderColor = '#ff5a5a';
            }

            clearFieldError(field) {
                const parent = field.closest('.form-group') || field.closest('.checkbox-item') || field.parentNode;
                const existingError = parent.querySelector('.field-error');
                
                if (existingError) {
                    existingError.remove();
                }
                
                // Reset field styling
                field.style.borderColor = '';
            }

            getFieldLabel(field) {
                const label = field.closest('.form-group')?.querySelector('label');
                if (label) {
                    return label.textContent.replace('*', '').trim();
                }
                
                return field.name || 'This field';
            }

            collectFormData() {
                // Build FormData directly so file u2ploads and arrays are preserved
                const formData = new FormData(this.form);

                // Handle performance_type[] with specifications - REMOVE the default entries first
                formData.delete('performance_type[]');
                
                // Now manually add performance types with specifications
                const performanceCheckboxes = document.querySelectorAll('input[name="performance_type[]"]:checked');
                performanceCheckboxes.forEach(checkbox => {
                    let value = checkbox.value;
                    
                    // Find the specification input field that's a sibling in the same checkbox-item
                    const checkboxItem = checkbox.closest('.checkbox-item');
                    const specifyInput = checkboxItem.querySelector('.inline-text');
                    
                    if (specifyInput && specifyInput.value.trim()) {
                        value += ': ' + specifyInput.value.trim();
                    }
                    
                    // Append to FormData
                    formData.append('performance_type[]', value);
                });

                // Rest of the function remains the same...
                // Append participation rows
                const participationRows = this.participationTable.querySelectorAll('tr');
                participationRows.forEach((row, idx) => {
                    const date = row.querySelector('input[name="participation_date[]"]')?.value || '';
                    const event = row.querySelector('input[name="participation_event[]"]')?.value || '';
                    const level = row.querySelector('select[name="participation_level[]"]')?.value || '';
                    const rank = row.querySelector('input[name="participation_rank[]"]')?.value || '';

                    formData.append('participation_date[]', date);
                    formData.append('participation_event[]', event);
                    formData.append('participation_level[]', level);
                    formData.append('participation_rank[]', rank);
                });

                // Append affiliation rows
                const affiliationRows = this.affiliationTable.querySelectorAll('tr');
                affiliationRows.forEach((row, idx) => {
                    const position = row.querySelector('input[name="affiliation_position[]"]')?.value || '';
                    const org = row.querySelector('input[name="affiliation_organization[]"]')?.value || '';
                    const years = row.querySelector('input[name="affiliation_years[]"]')?.value || '';

                    formData.append('affiliation_position[]', position);
                    formData.append('affiliation_organization[]', org);
                    formData.append('affiliation_years[]', years);
                });

                // Append signature image if present
                const sigDataUrl = this.getSignatureData();
                if (sigDataUrl) {
                    // Pass the base64 data directly to PHP
                    formData.append('signature_data', sigDataUrl);
                }

                return formData;
            }

            async handleSubmit(e) {
                console.log('Form submit event triggered'); // Debug log
                e.preventDefault();
                
                console.log('isSubmitting:', this.isSubmitting); // Debug log
                if (this.isSubmitting) return;
                
                // Clear any previous success messages
                const existingSuccess = document.querySelector('.success-message');
                if (existingSuccess) {
                    existingSuccess.remove();
                }
                
                console.log('About to validate form...'); // Debug log
                // Validate form
                if (!(await this.validateForm())) {
                    console.log('Form validation failed'); // Debug log
                    this.showAlert('Please correct the errors above before submitting.', 'error');
                    return;
                }
                
                console.log('Form validation passed, starting submission...'); // Debug log
                
                this.isSubmitting = true;
                this.submitBtn.disabled = true;
                this.submitBtn.textContent = 'Submitting...';
                
                try {
                    let formData = this.collectFormData();

                    const otpRequest = new FormData();
                    otpRequest.append('otp_action', 'start');
                    otpRequest.append('email', formData.get('email'));
                    const otpStartResponse = await fetch('performer-profile-form.php', {
                        method: 'POST',
                        body: otpRequest,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const otpStartResult = await otpStartResponse.json();
                    if (!otpStartResponse.ok || !otpStartResult.success) {
                        this.showAlert(otpStartResult.message || 'Unable to send the verification code.', 'error');
                        return;
                    }

                    const otp = await showOtpModal(formData.get('email'), otpStartResult, async (value) => {
                        const otpVerify = new FormData();
                        otpVerify.append('otp_action', 'verify');
                        otpVerify.append('email', formData.get('email'));
                        otpVerify.append('otp', value);
                        const otpVerifyResponse = await fetch('performer-profile-form.php', {
                            method: 'POST',
                            body: otpVerify,
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        return await otpVerifyResponse.json();
                    });
                    if (!otp) {
                        this.showAlert('Email verification is required before submission.', 'error');
                        return;
                    }

                    // POST to backend
                    // Post to the same PHP handler in the current directory
                    const resp = await fetch('performer-profile-form.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            // Let browser set Content-Type for FormData
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    console.log('Response status:', resp.status);
                    console.log('Response headers:', resp.headers);
                    
                    // Get the raw response text first
                    const responseText = await resp.text();
                    console.log('Raw response:', responseText);
                    
                    // Try to parse as JSON
                    let result = null;
                    try {
                        result = JSON.parse(responseText);
                    } catch (parseError) {
                        console.error('JSON parse error:', parseError);
                        console.error('Response was not valid JSON:', responseText.substring(0, 500));
                        this.showAlert('Server returned invalid response format. Please check the console for details.', 'error');
                        return;
                    }
                    
                    console.log('Parsed result:', result); // Debug log

                    if (resp.ok && result && result.success) {
                        this.showSuccessMessage();
                        // Don't automatically reset the form - let users decide if they want to submit another application
                        // setTimeout(() => this.resetForm(), 2000);
                    } else {
                        const msg = (result && result.message) ? result.message : `Server error (${resp.status}): ${resp.statusText}`;
                        console.error('Server error details:', msg); // Debug log
                        this.showAlert(msg, 'error');
                    }

                } catch (error) {
                    console.error('Submission error:', error);
                    this.showAlert('An error occurred while submitting the form. Please try again.', 'error');
                } finally {
                    this.isSubmitting = false;
                    this.submitBtn.disabled = false;
                    this.submitBtn.textContent = 'Submit Application';
                }
            }

            showSuccessMessage() {
                const successDiv = document.createElement('div');
                successDiv.className = 'success-message';
                successDiv.innerHTML = `
                    <strong>Application Submitted Successfully!</strong><br>
                    Your performer profile has been submitted for review. You will be contacted within 3-5 business days.<br><br>
                    <strong>Important:</strong> Please save your SR Code to check your application status later.<br>
                    <a href="check-status.php" style="color: #ff5a5a; text-decoration: underline;">Click here to check your application status</a>
                `;
                
                this.form.insertBefore(successDiv, this.form.firstChild);
                
                // Scroll to top to show success message
                successDiv.scrollIntoView({ behavior: 'smooth' });
            }

            showAlert(message, type = 'info') {
                // Remove existing alerts
                const existingAlert = document.querySelector('.form-alert');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                const alertDiv = document.createElement('div');
                alertDiv.className = 'form-alert';
                alertDiv.style.cssText = `
                    padding: 1rem;
                    margin-bottom: 1rem;
                    border-radius: 4px;
                    font-weight: 500;
                    ${type === 'error' 
                        ? 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' 
                        : 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;'
                    }
                `;
                alertDiv.textContent = message;
                
                this.form.insertBefore(alertDiv, this.form.firstChild);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
                
                // Scroll to alert
                alertDiv.scrollIntoView({ behavior: 'smooth' });
            }

            resetForm() {
                this.form.reset();
                
                // Reset dynamic tables to original single row
                this.participationTable.innerHTML = `
                    <tr>
                        <td><input type="date" name="participation_date[]"></td>
                        <td><input type="text" name="participation_event[]"></td>
                        <td>
                            <select name="participation_level[]">
                                <option value="">Select</option>
                                <option value="local">Local</option>
                                <option value="regional">Regional</option>
                                <option value="national">National</option>
                                <option value="international">International</option>
                            </select>
                        </td>
                        <td><input type="text" name="participation_rank[]"></td>
                        <td>-</td>
                    </tr>
                `;
                
                this.affiliationTable.innerHTML = `
                    <tr>
                        <td><input type="text" name="affiliation_position[]"></td>
                        <td><input type="text" name="affiliation_organization[]"></td>
                        <td><input type="text" name="affiliation_years[]" placeholder="e.g., 2020-2023"></td>
                        <td>-</td>
                    </tr>
                `;
                
                // Reset photo
                const photoPlaceholder = document.getElementById('photoPreview');
                if (photoPlaceholder) {
                    photoPlaceholder.innerHTML = '<span>2" x 2" ID Picture</span>';
                }
                
                // Reset photo upload input
                const photoUpload = document.getElementById('photoUpload');
                if (photoUpload) {
                    photoUpload.value = '';
                }
                
                // Clear all errors
                document.querySelectorAll('.field-error').forEach(error => error.remove());
                document.querySelectorAll('input, select').forEach(field => {
                    field.style.borderColor = '';
                });
                
                // Reset other input visibility
                const otherInput = document.getElementById('performanceOther');
                if (otherInput) {
                    otherInput.style.display = 'none';
                    otherInput.required = false;
                }
            }

            handleCancel(e) {
                e.preventDefault();
                
                if (confirm('Are you sure you want to cancel? All entered data will be lost.')) {
                    // Redirect to dashboard or previous page
                    window.location.href = '../index.php';
                }
                // If user clicks "Cancel" in the dialog, do nothing (stay on the form)
            }
        }

        // Global functions for HTML onclick events
        function addParticipationRow() {
            if (window.performerForm && window.performerForm.addParticipationRow) {
                window.performerForm.addParticipationRow();
            }
        }

        function addCompetitionRow() {
            if (window.performerForm && window.performerForm.addCompetitionRow) {
                window.performerForm.addCompetitionRow();
            }
        }

        function addAffiliationRow() {
            if (window.performerForm && window.performerForm.addAffiliationRow) {
                window.performerForm.addAffiliationRow();
            }
        }

        function addInstructor() {
            const instructorsList = document.getElementById('instructorsList');
            const instructorItem = document.createElement('div');
            instructorItem.className = 'instructor-item';
            instructorItem.innerHTML = `
                <input type="text" name="instructors[]" placeholder="Instructor Name" class="instructor-input">
                <button type="button" class="remove-instructor-btn" onclick="removeInstructor(this)">Remove</button>
            `;
            instructorsList.appendChild(instructorItem);
            
            // Show remove button on all items except the first one
            updateInstructorRemoveButtons();
        }

        function removeInstructor(button) {
            const instructorsList = document.getElementById('instructorsList');
            const instructorItem = button.closest('.instructor-item');
            
            // Don't allow removal if this is the only item
            if (instructorsList.children.length <= 1) {
                return;
            }
            
            instructorItem.remove();
            
            // Update remove button visibility
            updateInstructorRemoveButtons();
        }

        function updateInstructorRemoveButtons() {
            const instructorsList = document.getElementById('instructorsList');
            const items = instructorsList.querySelectorAll('.instructor-item');
            
            items.forEach((item, index) => {
                const removeBtn = item.querySelector('.remove-instructor-btn');
                if (items.length > 1) {
                    removeBtn.style.display = 'inline-block';
                } else {
                    removeBtn.style.display = 'none';
                }
            });
        }

        function removeParticipationRow(button) {
            const row = button.closest('tr');
            const tbody = row.parentNode;
            
            // Don't allow removal if this is the only row or the first row
            if (tbody.children.length <= 1 || row === tbody.firstElementChild) {
                return;
            }
            
            row.remove();
        }

        function removeCompetitionRow(button) {
            const row = button.closest('tr');
            const tbody = row.parentNode;
            
            // Don't allow removal if this is the only row or the first row
            if (tbody.children.length <= 1 || row === tbody.firstElementChild) {
                return;
            }
            
            row.remove();
        }

        function removeAffiliationRow(button) {
            const row = button.closest('tr');
            const tbody = row.parentNode;
            
            // Don't allow removal if this is the only row or the first row
            if (tbody.children.length <= 1 || row === tbody.firstElementChild) {
                return;
            }
            
            row.remove();
        }

        function clearSignature() {
            console.log('Global clearSignature() called'); // Debug log
            
            if (!window.performerForm) {
                console.error('performerForm not found on window object');
                return;
            }
            
            console.log('performerForm object:', window.performerForm);
            console.log('Available methods:', Object.getOwnPropertyNames(Object.getPrototypeOf(window.performerForm)));
            
            if (!window.performerForm.clearSignature) {
                console.error('clearSignature method not found on performerForm');
                // Try to call it directly
                if (window.performerForm.canvas && window.performerForm.ctx) {
                    console.log('Trying to clear canvas directly...');
                    const canvas = window.performerForm.canvas;
                    const ctx = window.performerForm.ctx;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    console.log('Canvas cleared directly');
                }
                return;
            }
            
            window.performerForm.clearSignature();
        }

        function viewApplicationStatus() {
            // Redirect to the dedicated status checking page
            window.location.href = 'check-status.php';
        }

        // Initialize the form when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOM loaded, initializing PerformerProfileForm...');
            try {
                window.performerForm = new PerformerProfileForm();
                console.log('PerformerProfileForm initialized successfully');
                console.log('performerForm methods:', Object.getOwnPropertyNames(Object.getPrototypeOf(window.performerForm)));
            } catch (error) {
                console.error('Error initializing PerformerProfileForm:', error);
            }
        });

        // Add styles for dynamic elements
        const style = document.createElement('style');
        style.textContent = `
            .remove-row-btn {
                background: #ff5a5a;
                color: white;
                border: none;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.3s ease;
            }
            
            .remove-row-btn:hover {
                background: #ff3333;
            }
            
            .field-error {
                color: #ff5a5a !important;
                font-size: 0.75rem !important;
                margin-top: 0.25rem !important;
                display: block !important;
                line-height: 1.2 !important;
                min-height: 1rem !important;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>