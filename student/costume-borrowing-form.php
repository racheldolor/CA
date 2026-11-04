<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();
$success_message = '';
$error_message = '';

// Get student information
$student_id = $_SESSION['user_id'];
$student_info = null;

try {
    // Students can only be retrieved from student_artists and applications tables
    // First get student from student_artists table
    $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE id = ?");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If student found, get present_address from applications table
    if ($student_info && isset($student_info['email'])) {
        $stmt = $pdo->prepare("SELECT present_address FROM applications WHERE email = ? AND application_status = 'approved' ORDER BY submitted_at DESC LIMIT 1");
        $stmt->execute([$student_info['email']]);
        $application_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($application_data && !empty($application_data['present_address'])) {
            // Use only present_address from applications table
            $student_info['address'] = $application_data['present_address'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching student info: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $campus = trim($_POST['campus'] ?? '');
        $requester_name = trim($_POST['requester_name'] ?? '');
        $college_office = trim($_POST['college_office'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        // Equipment categories with specifications and quantities
        $equipment_details = [];
        $approved_items_array = [];
        $selected_categories = $_POST['equipment_types'] ?? [];
        
        // Debug: Log what we received
        error_log("Selected categories: " . print_r($selected_categories, true));
        error_log("All POST data: " . print_r($_POST, true));
        
        foreach ($selected_categories as $category) {
            $specification_field = $category; // costumes, equipment, instruments, props, others
            $specification = trim($_POST[$specification_field] ?? '');
            $qty_field = 'qty_' . $category;
            $quantity = intval($_POST[$qty_field] ?? 1);
            
            // Ensure quantity is at least 1
            if ($quantity < 1) $quantity = 1;
            
            error_log("Category: $category, Specification: '$specification', Qty: $quantity");
            
            // Build item name for display
            if (!empty($specification)) {
                $equipment_details[] = ucfirst($category) . ': ' . $specification . ' (Qty: ' . $quantity . ')';
            } else {
                $equipment_details[] = ucfirst($category) . ' (Qty: ' . $quantity . ')';
            }
            
            // Build approved_items array structure for later approval
            $approved_items_array[] = [
                'category' => $category,
                'name' => !empty($specification) ? $specification : ucfirst($category),
                'quantity' => $quantity
            ];
        }
        
        // Create item name from selected categories and their specifications
        $item_name = !empty($equipment_details) ? implode('; ', $equipment_details) : 'General Equipment Request';
        
        // Convert approved_items to JSON for storage
        $approved_items_json = json_encode($approved_items_array);
        
        error_log("Final item_name: " . $item_name);
        error_log("Approved items JSON: " . $approved_items_json);
        
        $date_of_request = $_POST['date_of_request'] ?? date('Y-m-d');
        $dates_of_use = trim($_POST['dates_of_use'] ?? '');
        $times_of_use = trim($_POST['times_of_use'] ?? '');
        $estimated_return_date = $_POST['estimated_return_date'] ?? '';
        $purpose = trim($_POST['purpose'] ?? '');

        // Validate required fields
        if (empty($requester_name) || empty($email) || empty($purpose)) {
            throw new Exception('Please fill in all required fields.');
        }

        if (empty($equipment_details)) {
            throw new Exception('Please select at least one equipment category.');
        }

        // Insert borrowing request
        // Get a valid item_id from inventory (or create a general placeholder)
        $item_check = $pdo->query("SELECT id FROM inventory LIMIT 1");
        $existing_item = $item_check->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_item) {
            $default_item_id = $existing_item['id'];
        } else {
            // Create a general placeholder item if no inventory items exist
            $placeholder_stmt = $pdo->prepare("
                INSERT INTO inventory (item_name, category, quantity, available_quantity, status)
                VALUES ('General Equipment Request', 'General', 1, 1, 'available')
                RETURNING id
            ");
            $placeholder_stmt->execute();
            $default_item_id = $placeholder_stmt->fetchColumn() ?: 1;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO borrowing_requests (
                student_id, student_campus, student_name, student_email,
                student_contact, item_id, item_name, approved_items, purpose, start_date, end_date, 
                dates_of_use, venue, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $student_id,
            $campus,
            $requester_name,
            $email,
            $contact_number,
            $default_item_id,
            $item_name,
            $approved_items_json,
            $purpose,
            $date_of_request,
            $estimated_return_date,
            $dates_of_use,
            $college_office
        ]);

        if ($result) {
            $success_message = "Your borrowing request has been submitted successfully!";
        } else {
            throw new Exception('Failed to submit borrowing request. Please try again.');
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Culture and Arts Equipment Borrowing Form - BatStateU TNEU</title>
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
        }

        .performer-profile-container {
            max-width: 900px;
            margin: 0 auto;
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
            background: #ff5a5a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
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
            min-width: 120px;
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

        /* Form Grid */
        .form-grid {
            display: grid;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
            font-size: 0.9rem;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 40px;
            height: 40px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff5a5a;
            box-shadow: 0 0 0 2px rgba(255, 90, 90, 0.2);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            align-items: end;
        }

        .form-group.half {
            flex: 1;
        }

        .form-group.quarter {
            flex: 0.5;
        }

        /* Equipment Checkbox Section */
        .equipment-section {
            margin: 1rem 0;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: 150px 1fr 100px;
            gap: 1rem;
            align-items: start;
        }

        .checkbox-list {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
            height: 42px;
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

        .specification-inputs {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .specification-inputs input {
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
            font-size: 0.9rem;
            height: 42px;
        }

        .specification-inputs input:disabled {
            background: #f5f5f5;
            color: #999;
        }

        .quantity-inputs {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .quantity-inputs input {
            padding: 0.5rem;
            border: 1px solid #333;
            border-radius: 4px;
            font-size: 0.9rem;
            height: 42px;
            text-align: center;
            width: 100%;
        }

        .quantity-inputs input:disabled {
            background: #f5f5f5;
            color: #999;
        }

        /* Agreement Section */
        .agreement-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1.5rem 0;
        }

        .agreement-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
        }

        .agreement-item input[type="checkbox"] {
            display: none;
        }

        .agreement-item .checkmark {
            margin-top: 2px;
        }

        .agreement-item input[type="checkbox"]:checked + .checkmark::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }

        .agreement-text {
            font-size: 0.85rem;
            line-height: 1.4;
            color: #555;
        }

        /* Signature Section */
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            gap: 2rem;
            border: 1px solid #333;
            padding: 1rem;
        }

        .signature-box {
            flex: 1;
            text-align: center;
        }

        .signature-label {
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 40px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: end;
            justify-content: center;
        }

        .signature-details {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
        }

        /* Button Styles */
        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-family: 'Montserrat', sans-serif;
        }

        .btn-primary {
            background: #ff5a5a;
            color: white;
        }

        .btn-primary:hover {
            background: #ff3333;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Required field marker */
        .required {
            color: #ff5a5a;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .performer-profile-container {
                margin: 0;
                padding: 0;
            }
            
            .form-body {
                padding: 1rem;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .header-info {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .signature-section {
                flex-direction: column;
                gap: 1rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .checkbox-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        /* Mobile Responsive - 400x651 */
        @media (max-width: 480px) {
            * {
                box-sizing: border-box;
            }

            body {
                padding: 0;
                margin: 0;
                overflow-x: hidden;
            }

            .performer-profile-container {
                margin: 0;
                padding: 0;
                max-width: 100%;
                width: 100%;
                box-shadow: none;
            }

            /* Header */
            .header-content {
                padding: 0.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .header-content h1 {
                font-size: 1.1rem;
                line-height: 1.3;
                text-align: center;
            }

            .header-info {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
                font-size: 0.85rem;
            }

            .header-info > div {
                display: flex;
                flex-direction: column;
                gap: 0.25rem;
            }

            /* Form Body */
            .form-body {
                padding: 0.75rem;
            }

            /* Form Groups */
            .form-group {
                margin-bottom: 0.75rem;
            }

            .form-group label {
                font-size: 0.85rem;
                margin-bottom: 0.35rem;
                font-weight: 600;
            }

            .form-group input,
            .form-group select,
            .form-group textarea {
                width: 100%;
                padding: 0.6rem;
                font-size: 0.9rem;
                border: 1px solid #333;
            }

            .form-group input[type="date"] {
                padding: 0.55rem 0.6rem;
            }

            .form-group textarea {
                min-height: 60px;
                height: auto;
            }

            /* Form Rows */
            .form-row {
                flex-direction: column;
                gap: 0.75rem;
                align-items: stretch;
            }

            .form-group.half,
            .form-group.quarter {
                flex: 1;
                width: 100%;
            }

            /* Checkbox Grid */
            .checkbox-grid {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            .checkbox-list {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }

            .checkbox-item {
                display: flex;
                flex-direction: row;
                align-items: flex-start;
                gap: 0.5rem;
                width: 100%;
            }

            .checkbox-item label {
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                font-size: 0.9rem;
                width: 100%;
            }
            
            .specification-inputs,
            .quantity-inputs {
                width: 100%;
            }

            .checkbox-item .checkmark {
                width: 18px;
                height: 18px;
                border: 2px solid #333;
                flex-shrink: 0;
                margin-top: 2px;
            }

            .checkbox-item input[type="text"],
            .checkbox-item textarea {
                width: 100%;
                padding: 0.6rem;
                font-size: 0.85rem;
                margin-top: 0.25rem;
            }

            /* Equipment Section */
            .equipment-section {
                margin: 0.75rem 0;
            }

            .equipment-section h3 {
                font-size: 1rem;
                margin-bottom: 0.75rem;
            }

            /* Signature Section */
            .signature-section {
                flex-direction: column;
                gap: 0.75rem;
                margin: 1rem 0;
            }

            .signature-group {
                width: 100%;
            }

            .signature-group label {
                font-size: 0.85rem;
            }

            .signature-line {
                margin-top: 0.5rem;
                padding: 0.5rem 0;
            }

            /* Responsibility Checkbox */
            .responsibility-section {
                margin: 1rem 0;
                padding: 0.75rem;
            }

            .responsibility-section label {
                font-size: 0.85rem;
                line-height: 1.4;
            }

            .responsibility-section input[type="checkbox"] {
                width: 18px;
                height: 18px;
                margin-right: 0.5rem;
                flex-shrink: 0;
            }

            /* Buttons */
            .btn-group {
                flex-direction: column;
                gap: 0.5rem;
                margin-top: 1rem;
            }

            .submit-btn,
            .cancel-btn {
                width: 100%;
                padding: 0.75rem;
                font-size: 0.95rem;
                min-height: 44px;
            }

            /* Success/Error Messages */
            .success-message,
            .error-message {
                margin: 0.75rem;
                padding: 0.75rem;
                font-size: 0.9rem;
                border-radius: 4px;
            }

            /* Section Titles */
            h2, h3 {
                font-size: 1.05rem;
                margin-bottom: 0.75rem;
            }

            /* Required Asterisk */
            .required {
                color: #dc2626;
                font-size: 0.9rem;
            }

            /* Form Grid */
            .form-grid {
                gap: 0.75rem;
            }

            /* Fix for right-aligned fields */
            .form-group input,
            .form-group select,
            .form-group textarea {
                text-align: left;
                direction: ltr;
            }

            /* Specification fields */
            .specification-field {
                width: 100%;
                margin-top: 0.35rem;
            }

            /* Touch improvements */
            input,
            select,
            textarea,
            button {
                -webkit-appearance: none;
                appearance: none;
            }

            select {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 0.75rem center;
                padding-right: 2.5rem;
            }

            /* Prevent zoom on input focus (iOS) */
            input[type="text"],
            input[type="email"],
            input[type="tel"],
            input[type="date"],
            select,
            textarea {
                font-size: 16px;
            }

            /* Form container */
            .form-container {
                width: 100%;
                overflow-x: hidden;
            }
        }
    </style>
</head>
<body>
    <div class="performer-profile-container">
        <div class="performer-profile-card">
            <div class="form-header">
                <h1 class="form-title">CULTURE AND ARTS EQUIPMENT BORROWING FORM</h1>
            </div>

            <div class="form-body">
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group half">
                                <label for="campus">Campus:</label>
                                <input type="text" id="campus" name="campus" value="<?= htmlspecialchars($student_info['campus'] ?? '') ?>">
                            </div>
                            <div class="form-group half">
                                <label for="requester_name">Name of Requester: <span class="required">*</span></label>
                                <input type="text" id="requester_name" name="requester_name" required 
                                       value="<?= htmlspecialchars(($student_info['first_name'] ?? '') . ' ' . ($student_info['middle_name'] ?? '') . ' ' . ($student_info['last_name'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group half">
                                <label for="college_office">College/Office/Organization:</label>
                                <input type="text" id="college_office" name="college_office" value="<?= htmlspecialchars($student_info['college'] ?? '') ?>">
                            </div>
                            <div class="form-group half">
                                <label for="contact_number">Contact Number:</label>
                                <input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($student_info['contact_number'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group half">
                                <label for="email">Email Address: <span class="required">*</span></label>
                                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($student_info['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">Address:</label>
                            <textarea id="address" name="address"><?= htmlspecialchars($student_info['address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Equipment Categories Section -->
                    <div class="form-section">
                        <h3 class="section-title">Categories of Equipment being Requested for Borrowing:</h3>
                        
                        <div class="equipment-section">
                            <div class="checkbox-grid">
                                <div class="checkbox-list">
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="costumes" name="equipment_types[]" value="costumes">
                                        <span class="checkmark"></span>
                                        <label for="costumes">Costumes</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="equipment" name="equipment_types[]" value="equipment">
                                        <span class="checkmark"></span>
                                        <label for="equipment">Equipment/Gadget</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="instruments" name="equipment_types[]" value="instruments">
                                        <span class="checkmark"></span>
                                        <label for="instruments">Instruments</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="props" name="equipment_types[]" value="props">
                                        <span class="checkmark"></span>
                                        <label for="props">Props</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="others_check" name="equipment_types[]" value="others">
                                        <span class="checkmark"></span>
                                        <label for="others_check">Others, specify</label>
                                    </div>
                                </div>
                                <div class="specification-inputs">
                                    <input type="text" name="costumes" placeholder="Specify costumes..." readonly style="background: #f5f5f5; color: #999;">
                                    <input type="text" name="equipment" placeholder="Specify equipment/gadget..." readonly style="background: #f5f5f5; color: #999;">
                                    <input type="text" name="instruments" placeholder="Specify instruments..." readonly style="background: #f5f5f5; color: #999;">
                                    <input type="text" name="props" placeholder="Specify props..." readonly style="background: #f5f5f5; color: #999;">
                                    <input type="text" name="others" placeholder="Specify others..." readonly style="background: #f5f5f5; color: #999;">
                                </div>
                                <div class="quantity-inputs">
                                    <input type="number" name="qty_costumes" min="1" value="1" disabled style="background: #f5f5f5; color: #999;">
                                    <input type="number" name="qty_equipment" min="1" value="1" disabled style="background: #f5f5f5; color: #999;">
                                    <input type="number" name="qty_instruments" min="1" value="1" disabled style="background: #f5f5f5; color: #999;">
                                    <input type="number" name="qty_props" min="1" value="1" disabled style="background: #f5f5f5; color: #999;">
                                    <input type="number" name="qty_others" min="1" value="1" disabled style="background: #f5f5f5; color: #999;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Date and Time Information Section -->
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group quarter">
                                <label for="date_of_request">Date of Request:</label>
                                <input type="date" id="date_of_request" name="date_of_request" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group quarter">
                                <label for="dates_of_use">Dates of Use:</label>
                                <input type="text" id="dates_of_use" name="dates_of_use" placeholder="e.g., Oct xx-xx, 2025">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group quarter">
                                <label for="times_of_use">Times of Use:</label>
                                <input type="text" id="times_of_use" name="times_of_use" placeholder="e.g., 8:00 AM - 5:00 PM">
                            </div>
                            <div class="form-group quarter">
                                <label for="estimated_return_date">Estimated Date of Return:</label>
                                <input type="date" id="estimated_return_date" name="estimated_return_date">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="purpose">Purpose: <span class="required">*</span></label>
                            <textarea id="purpose" name="purpose" required placeholder="Describe the purpose for borrowing the equipment..."></textarea>
                        </div>
                    </div>

                    <!-- Terms and Conditions Section -->
                    <div class="form-section">
                        <div class="agreement-section">
                            <div class="agreement-item">
                                <input type="checkbox" id="agreement1" name="agreement1" required>
                                <span class="checkmark"></span>
                                <div class="agreement-text">
                                    I understand that it is my responsibility to pick up the equipment and return it to the same location at the end of my borrowing period unless otherwise agreed by both parties. I agree to return the borrowed equipment to the Office of Culture and Arts in the same condition as when it was borrowed, except normal wear and tear.
                                </div>
                            </div>
                            <div class="agreement-item">
                                <input type="checkbox" id="agreement2" name="agreement2" required>
                                <span class="checkmark"></span>
                                <div class="agreement-text">
                                    I also understand that there is no charge for borrowing the equipment. However, in the event that the equipment was lost or damaged during the approved borrowing period, I agree to follow the university protocols relative to the replacement or repair of the damaged equipment.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">Submit Borrowing Request</button>
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Enable/disable equipment description fields based on checkbox selection
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('input[name="equipment_types[]"]');
            const inputs = {
                'costumes': document.querySelector('input[name="costumes"]'),
                'equipment': document.querySelector('input[name="equipment"]'),
                'instruments': document.querySelector('input[name="instruments"]'),
                'props': document.querySelector('input[name="props"]'),
                'others': document.querySelector('input[name="others"]')
            };
            const qtyInputs = {
                'costumes': document.querySelector('input[name="qty_costumes"]'),
                'equipment': document.querySelector('input[name="qty_equipment"]'),
                'instruments': document.querySelector('input[name="qty_instruments"]'),
                'props': document.querySelector('input[name="qty_props"]'),
                'others': document.querySelector('input[name="qty_others"]')
            };

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const value = this.value;
                    const input = inputs[value];
                    const qtyInput = qtyInputs[value];
                    
                    if (input) {
                        input.readOnly = !this.checked;
                        input.style.backgroundColor = this.checked ? '#fff' : '#f5f5f5';
                        input.style.color = this.checked ? '#333' : '#999';
                        if (!this.checked) {
                            input.value = '';
                        }
                    }
                    
                    if (qtyInput) {
                        qtyInput.disabled = !this.checked;
                        qtyInput.style.backgroundColor = this.checked ? '#fff' : '#f5f5f5';
                        qtyInput.style.color = this.checked ? '#333' : '#999';
                        if (!this.checked) {
                            qtyInput.value = '1';
                        }
                    }
                });
            });

            // Handle agreement checkbox clicks
            const agreementItems = document.querySelectorAll('.agreement-item');
            agreementItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Don't trigger if clicking on the actual checkbox input
                    if (e.target.type === 'checkbox') return;
                    
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                    }
                });
            });
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const equipmentChecked = document.querySelectorAll('input[name="equipment_types[]"]:checked');
            if (equipmentChecked.length === 0) {
                e.preventDefault();
                alert('Please select at least one equipment category.');
                return;
            }

            const agreement1 = document.getElementById('agreement1');
            const agreement2 = document.getElementById('agreement2');
            
            if (!agreement1.checked || !agreement2.checked) {
                e.preventDefault();
                alert('Please agree to all terms and conditions.');
                return;
            }
        });
    </script>
</body>
</html>