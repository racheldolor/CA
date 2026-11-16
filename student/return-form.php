<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once '../config/database.php';

$borrowing_request_id = $_GET['request_id'] ?? null;
$borrowing_request = null;
$approved_items = [];

try {
    $pdo = getDBConnection();
    
    // Get student information
    $student_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM student_artists WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        header("Location: ../index.php");
        exit();
    }
    
    // Get all active borrowing requests for this student
    $stmt = $pdo->prepare("
        SELECT br.*, br.approved_items, br.item_name, br.quantity_requested 
        FROM borrowing_requests br 
        WHERE br.student_id = ? AND br.status = 'approved' AND br.current_status = 'active'
        ORDER BY br.created_at DESC
    ");
    $stmt->execute([$student_id]);
    $all_borrowing_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $all_approved_items = [];
    $borrowing_request_details = [];
    
    // Collect all approved items from all active borrowing requests
    foreach ($all_borrowing_requests as $request) {
        // Debug: Log the request data
        error_log("Processing request ID: " . $request['id']);
        error_log("Original item_name: " . ($request['item_name'] ?? 'NULL'));
        error_log("Approved items JSON: " . ($request['approved_items'] ?? 'NULL'));
        
        // If staff approved specific items, use those instead of what student typed
        if (!empty($request['approved_items'])) {
            $approved_items = json_decode($request['approved_items'], true);
            
            if (is_array($approved_items) && count($approved_items) > 0) {
                // Create an entry for each approved item
                foreach ($approved_items as $approved_item) {
                    $item = [
                        'id' => $approved_item['id'],
                        'name' => $approved_item['name'], // This is the staff-approved name
                        'borrowing_request_id' => $request['id'],
                        'request_date' => $request['created_at'],
                        'due_date' => $request['end_date'] ?? $request['due_date'] ?? null,
                        'quantity' => $approved_item['quantity']
                    ];
                    $all_approved_items[] = $item;
                    error_log("Added approved item: " . $approved_item['name']);
                }
            } else {
                // Fallback to original item if approved_items is empty or invalid
                $item = [
                    'id' => $request['item_id'],
                    'name' => $request['item_name'],
                    'borrowing_request_id' => $request['id'],
                    'request_date' => $request['created_at'],
                    'due_date' => $request['end_date'] ?? $request['due_date'] ?? null,
                    'quantity' => $request['quantity_approved'] ?? $request['quantity_requested'] ?? 1
                ];
                $all_approved_items[] = $item;
                error_log("Used fallback item: " . $request['item_name']);
            }
        } else {
            // No approved_items field, use original request
            $item = [
                'id' => $request['item_id'],
                'name' => $request['item_name'],
                'borrowing_request_id' => $request['id'],
                'request_date' => $request['created_at'],
                'due_date' => $request['end_date'] ?? $request['due_date'] ?? null,
                'quantity' => $request['quantity_approved'] ?? $request['quantity_requested'] ?? 1
            ];
            $all_approved_items[] = $item;
            error_log("No approved_items, used original: " . $request['item_name']);
        }
        
        // Store request details for display
        $borrowing_request_details[] = [
            'id' => $request['id'],
            'created_at' => $request['created_at'],
            'end_date' => $request['end_date'] ?? $request['due_date'] ?? null
        ];
    }
    
    // For backward compatibility, set the first request as primary if exists
    $borrowing_request = !empty($all_borrowing_requests) ? $all_borrowing_requests[0] : null;
    $borrowing_request_id = $borrowing_request ? $borrowing_request['id'] : null;
    $approved_items = $all_approved_items;
    
    // Debug: Check what we found
    if ($borrowing_request) {
        error_log("Borrowing request ID: " . $borrowing_request['id']);
        error_log("Total approved items found: " . count($all_approved_items));
    }
    
    if (empty($all_approved_items)) {
        $_SESSION['error_message'] = 'No active borrowing requests found or no items to return.';
        header("Location: dashboard.php?section=costume-borrowing");
        exit();
    }
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Database error occurred.';
    header("Location: dashboard.php?section=costume-borrowing");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Form - Student Dashboard</title>
    <link rel="icon" type="image/jpeg" href="../assets/logo.jpg?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .header {
            background: #ff5a5a;
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            opacity: 0.9;
        }

        .form-content {
            padding: 2rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
            border-bottom: 2px solid #ff5a5a;
            padding-bottom: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .input-group input,
        .input-group select,
        .input-group textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
            transition: border-color 0.3s ease;
        }

        .input-group input:focus,
        .input-group select:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #ff5a5a;
            box-shadow: 0 0 0 3px rgba(255, 90, 90, 0.1);
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .checkbox-item:hover {
            border-color: #ff5a5a;
            background-color: rgba(255, 90, 90, 0.05);
        }

        .checkbox-item input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid #333;
            background: #fff;
            position: relative;
            flex-shrink: 0;
            border-radius: 4px;
        }

        .checkbox-item input[type="checkbox"]:checked + .checkmark::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 14px;
            font-weight: bold;
            color: #ff5a5a;
        }

        .checkbox-label {
            font-size: 1rem;
            color: #333;
            line-height: 1.4;
        }

        .return-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #eee;
            margin-bottom: 1.5rem;
        }

        .return-section h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .received-by-section {
            background: #f8f9fa;
            border: 2px solid #ff5a5a;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-radius: 8px;
        }

        .received-by-header {
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            text-align: center;
            color: #ff5a5a;
        }

        .date-time-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 1rem;
            align-items: center;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            min-width: 140px;
        }

        .btn-primary {
            background: #ff5a5a;
            color: white;
        }

        .btn-primary:hover {
            background: #ff3333;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header {
                padding: 1.5rem;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .form-content {
                padding: 1.5rem;
            }

            .form-grid,
            .date-time-grid {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="header">
                <h1>Equipment Return Form</h1>
                <p>Office of Culture and Arts</p>
            </div>
            
            <div class="form-content">
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <?= htmlspecialchars($_SESSION['error_message']) ?>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <form method="POST" action="process-return.php">
                    <!-- Hidden field to indicate multiple requests mode -->
                    <input type="hidden" name="multiple_requests" value="1">
                    
                    <!-- Borrowing Request Details -->
                    <div class="form-section">
                        <h3>Borrowing Request Details</h3>
                        <?php if (count($borrowing_request_details) > 1): ?>
                            <p style="color: #666; margin-bottom: 1rem;">
                                You have <?= count($borrowing_request_details) ?> active borrowing requests. 
                                You can return items from any of these requests.
                            </p>
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <div class="input-group">
                                <label>Request Date Range</label>
                                <input type="text" value="<?php
                                    if (count($borrowing_request_details) > 1) {
                                        $earliest = min(array_column($borrowing_request_details, 'created_at'));
                                        $latest = max(array_column($borrowing_request_details, 'created_at'));
                                        echo date('M j, Y', strtotime($earliest)) . ' - ' . date('M j, Y', strtotime($latest));
                                    } else {
                                        echo isset($borrowing_request['created_at']) ? 
                                            date('F j, Y', strtotime($borrowing_request['created_at'])) : 'Not available';
                                    }
                                ?>" readonly>
                            </div>
                            <div class="input-group">
                                <label>Due Date</label>
                                <input type="text" id="dueDateInput" value="Not specified" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Items Being Returned -->
                    <div class="form-section">
                        <h3>Items Being Returned</h3>
                        <div class="input-group">
                            <label>Select Approved Items to Return</label>
                            <div class="checkbox-group">
                                <?php if (!empty($approved_items)): ?>
                                    <?php foreach ($approved_items as $index => $item): 
                                        // determine quantity available in the item metadata
                                        $item_qty = $item['quantity'] ?? $item['qty'] ?? 1;
                                        $item_due = $item['due_date'] ?? $item['estimated_return_date'] ?? null;
                                        $checkbox_value = json_encode([
                                            'id' => $item['id'],
                                            'name' => $item['name'],
                                            'borrowing_request_id' => $item['borrowing_request_id'],
                                            'due_date' => $item_due,
                                            'quantity' => $item_qty
                                        ]);
                                    ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" 
                                                   id="item_<?= $index ?>" 
                                                   name="selected_items[]" 
                                                   value="<?= htmlspecialchars($checkbox_value) ?>" 
                                                   checked>
                                            <span class="checkmark"></span>
                                            <label for="item_<?= $index ?>" class="checkbox-label">
                                                <?= htmlspecialchars($item['name']) ?>
                                                <?php if ($item_due): ?>
                                                    <span style="color: #6c757d; margin-left: 0.5rem; font-size: 0.85rem;">
                                                        (Due: <?= date('M j, Y', strtotime($item_due)) ?>)
                                                    </span>
                                                <?php endif; ?>
                                                <input type="number" 
                                                       class="return-qty" 
                                                       data-index="<?= $index ?>" 
                                                       min="1" 
                                                       value="<?= $item_qty ?>" 
                                                       max="<?= $item_qty ?>" 
                                                       style="width:80px; margin-left:1rem;">
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p style="color: #666; font-style: italic;">No approved items found.</p>
                                <?php endif; ?>
                            </div>
                            <small style="color: #666; margin-top: 0.5rem; display: block;">
                                Select the items you want to return. All items are selected by default.
                                <?php if (count($borrowing_request_details) > 1): ?>
                                    <br>Items from multiple borrowing requests are shown.
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>

                    <!-- Return Condition -->
                    <div class="return-section">
                        <h4>Return</h4>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="good_condition" name="condition" value="good_condition">
                                <span class="checkmark"></span>
                                <label for="good_condition" class="checkbox-label">
                                    Properties was/were returned in good condition
                                </label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="with_damage" name="condition" value="with_damage">
                                <span class="checkmark"></span>
                                <label for="with_damage" class="checkbox-label">
                                    Properties was/were returned with damage
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">Submit Return</button>
                        <a href="dashboard.php?section=costume-borrowing" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Handle checkbox clicking
        document.addEventListener('DOMContentLoaded', function() {
            // Apply click handling to all checkbox items (both conditions and items)
            const allCheckboxItems = document.querySelectorAll('.checkbox-item');
            
            allCheckboxItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.type === 'checkbox') return;
                    
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        
                        // Trigger change event to handle mutual exclusivity
                        checkbox.dispatchEvent(new Event('change'));
                    }
                });
            });
            
            // Make condition checkboxes mutually exclusive
            const conditionCheckboxes = document.querySelectorAll('input[name="condition"]');
            conditionCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        conditionCheckboxes.forEach(other => {
                            if (other !== this) {
                                other.checked = false;
                            }
                        });
                    }
                });
            });

            // Form validation
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const selectedItems = document.querySelectorAll('input[name="selected_items[]"]:checked');
                    const selectedCondition = document.querySelector('input[name="condition"]:checked');
                    
                    if (selectedItems.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one item to return.');
                        return;
                    }
                    
                    if (!selectedCondition) {
                        e.preventDefault();
                        alert('Please select the condition of the returned items.');
                        return;
                    }
                });
            }

            // Sync quantity input to checkbox JSON value and update Due Date display
            function safeParseJSON(val) {
                try {
                    return JSON.parse(val);
                } catch (err) {
                    return null;
                }
            }

            function updateDueDateFromSelection() {
                const dueInput = document.getElementById('dueDateInput');
                const firstChecked = document.querySelector('input[name="selected_items[]"]:checked');
                if (!firstChecked) {
                    if (dueInput) dueInput.value = 'Not specified';
                    return;
                }
                const data = safeParseJSON(firstChecked.value);
                if (data && data.due_date) {
                    const d = new Date(data.due_date);
                    if (!isNaN(d.getTime())) {
                        const options = { month: 'short', day: 'numeric', year: 'numeric' };
                        dueInput.value = d.toLocaleDateString('en-US', options);
                        return;
                    }
                }
                if (dueInput) dueInput.value = 'Not specified';
            }

            // Attach change listeners to checkboxes
            document.querySelectorAll('input[name="selected_items[]"]').forEach(cb => {
                cb.addEventListener('change', updateDueDateFromSelection);
            });

            // Attach listeners to quantity inputs to update corresponding checkbox JSON
            document.querySelectorAll('.return-qty').forEach(q => {
                q.addEventListener('change', function() {
                    let idx = this.dataset.index;
                    const checkbox = document.getElementById('item_' + idx);
                    if (!checkbox) return;
                    let data = safeParseJSON(checkbox.value) || {};
                    let val = parseInt(this.value) || 1;
                    if (data) {
                        data.quantity = val;
                        checkbox.value = JSON.stringify(data);
                    }
                });
            });

            // Initialize Due Date display
            updateDueDateFromSelection();
        });
    </script>
</body>
</html>