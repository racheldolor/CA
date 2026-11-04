<?php
session_start();
require_once '../config/database.php';

// Set proper headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start output buffering to prevent any stray output
ob_start();

try {
    // Check if user is logged in and has proper role
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'head') {
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $pdo = getDBConnection();

    // Ensure the table has the necessary columns for review tracking
    try {
        $pdo->exec("ALTER TABLE borrowing_requests ADD COLUMN IF NOT EXISTS review_notes TEXT");
        $pdo->exec("ALTER TABLE borrowing_requests ADD COLUMN IF NOT EXISTS reviewed_by INT");
        $pdo->exec("ALTER TABLE borrowing_requests ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE borrowing_requests ADD COLUMN IF NOT EXISTS approved_items JSON NULL");
    } catch (Exception $e) {
        // Columns might already exist, ignore error
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $request_id = $input['request_id'] ?? null;
    $action = $input['action'] ?? $input['status'] ?? null; // Support both 'action' and 'status' for backward compatibility
    $notes = $input['notes'] ?? '';
    $selected_items = $input['selected_items'] ?? [];
    $approved_items = $input['approved_items'] ?? []; // New format from approval modal

    // Use approved_items if available, otherwise fall back to selected_items
    if (!empty($approved_items)) {
        $selected_items = $approved_items;
    }

    // Validate input
    if (!$request_id || !$action) {
        throw new Exception('Request ID and action are required');
    }

    if (!in_array($action, ['approve', 'approved', 'reject', 'rejected'])) {
        throw new Exception('Invalid action. Must be approve/approved or reject/rejected');
    }

    // Normalize action to status
    $status = ($action === 'approve' || $action === 'approved') ? 'approved' : 'rejected';

    // Get the borrowing request to check campus
    $req_stmt = $pdo->prepare("SELECT student_campus FROM borrowing_requests WHERE id = ?");
    $req_stmt->execute([$request_id]);
    $borrowing_request = $req_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$borrowing_request) {
        throw new Exception('Borrowing request not found');
    }
    
    $request_campus = $borrowing_request['student_campus'];

    // Start transaction
    $pdo->beginTransaction();

    try {
        // If approving with selected items, validate and update inventory
        if ($status === 'approved' && !empty($selected_items)) {
            // Validate selected items exist and are available AND from same campus
            $item_ids = array_map(function($item) { return $item['id']; }, $selected_items);
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            
            $stmt = $pdo->prepare("
                SELECT id, item_name, quantity, status, campus 
                FROM inventory 
                WHERE id IN ($placeholders) AND status = 'available' AND campus = ?
            ");
            $params_with_campus = array_merge($item_ids, [$request_campus]);
            $stmt->execute($params_with_campus);
            $available_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($available_items) !== count($selected_items)) {
                throw new Exception('Some selected items are no longer available');
            }

            // Validate quantities but don't modify inventory
            foreach ($selected_items as $selected_item) {
                $item_id = $selected_item['id'];
                $borrow_qty = intval($selected_item['quantity']);
                
                // Get current item details
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
                $stmt->execute([$item_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                $current_qty = intval($current['quantity']);
                
                // Calculate currently borrowed quantity from active requests
                $borrowedQtySQL = "
                    SELECT COALESCE(SUM(
                        COALESCE((borrowed_item->>'quantity')::integer, 1)
                    ), 0) as total_borrowed
                    FROM borrowing_requests br
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                            THEN br.approved_items::jsonb ELSE '[]'::jsonb END
                    ) AS borrowed_item
                    WHERE br.status = 'approved'
                    AND br.current_status IN ('active', 'pending_return')
                    AND (borrowed_item->>'id')::integer = ?
                ";
                $borrowedStmt = $pdo->prepare($borrowedQtySQL);
                $borrowedStmt->execute([$item_id]);
                $currently_borrowed = $borrowedStmt->fetchColumn() ?: 0;
                
                // Calculate available quantity
                $available_qty = $current_qty - $currently_borrowed;
                
                // Check if requested quantity is available
                if ($borrow_qty > $available_qty) {
                    throw new Exception("Not enough quantity available for item: " . $item_id . ". Available: " . $available_qty . ", Requested: " . $borrow_qty);
                }
                
                // Note: We do NOT modify the inventory table quantity
                // The original quantity should remain unchanged
                // Available quantity is calculated dynamically based on borrowing records
            }

            // Store approved items as JSON in the request
            $approved_items_json = json_encode($selected_items);
        } else {
            $approved_items_json = null;
        }

        // Update the borrowing request
        $stmt = $pdo->prepare("
            UPDATE borrowing_requests 
            SET status = ?, 
                updated_at = CURRENT_TIMESTAMP,
                review_notes = ?,
                reviewed_by = ?,
                reviewed_at = CURRENT_TIMESTAMP,
                approved_items = ?,
                due_date = ?,
                current_status = ?
            WHERE id = ?
        ");

        // Set due date to end_date if approving
        $due_date = null;
        $current_status = null;
        if ($status === 'approved') {
            // Get the end_date from the request
            $temp_stmt = $pdo->prepare("SELECT end_date FROM borrowing_requests WHERE id = ?");
            $temp_stmt->execute([$request_id]);
            $temp_request = $temp_stmt->fetch(PDO::FETCH_ASSOC);
            
            $due_date = $temp_request['end_date'] ?? null;
            $current_status = 'active';
        }

        $result = $stmt->execute([
            $status,
            $notes,
            $_SESSION['user_id'],
            $approved_items_json,
            $due_date,
            $current_status,
            $request_id
        ]);

        if (!$result) {
            throw new Exception('Failed to update request status');
        }

        // Update inventory quantities when request is approved
        if ($status === 'approved' && !empty($selected_items)) {
            foreach ($selected_items as $item) {
                $item_id = $item['id'] ?? null;
                $quantity = intval($item['quantity'] ?? 1);
                
                if ($item_id && $quantity > 0) {
                    // Reduce available_quantity when item is borrowed
                    $inventory_stmt = $pdo->prepare("
                        UPDATE inventory 
                        SET available_quantity = GREATEST(0, available_quantity - ?), 
                            updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $inventory_stmt->execute([$quantity, $item_id]);
                }
            }
        }

        // Get the updated request details for logging
        $stmt = $pdo->prepare("SELECT * FROM borrowing_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Request not found');
        }

        // Create a detailed log message
        $log_message = "Requester: {$request['student_name']}, Status: $status";
        if ($status === 'approved' && !empty($selected_items)) {
            $item_names = array_map(function($item) { return $item['item_name'] ?? $item['name']; }, $selected_items);
            $log_message .= ", Approved items: " . implode(', ', $item_names);
        }

        // Log the admin action (after successful commit)
        try {
            logAdminAction(
                $pdo, 
                $_SESSION['user_id'], 
                "Borrowing request $status", 
                $request['student_id'], 
                $log_message
            );
        } catch (Exception $log_error) {
            // Don't fail the whole operation if logging fails
            error_log("Admin action logging failed: " . $log_error->getMessage());
        }

        // Commit transaction
        $pdo->commit();

        // Clean any output before sending JSON
        ob_clean();

        // Send success response
        echo json_encode([
            'success' => true,
            'message' => "Request has been $status successfully" . 
                        ($status === 'approved' && !empty($selected_items) ? 
                        " with " . count($selected_items) . " items" : ""),
            'data' => [
                'request_id' => $request_id,
                'status' => $status,
                'approved_items_count' => $status === 'approved' ? count($selected_items) : 0
            ]
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollback();
        throw $e;
    }

} catch (Exception $e) {
    // Clean any output before sending error JSON
    ob_clean();
    
    // Log the error for debugging
    error_log("Error in update_borrowing_request.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}

// End output buffering
ob_end_flush();
?>