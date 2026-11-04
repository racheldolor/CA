<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['item_id']) || !isset($input['status'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit();
    }
    
    $repair_item_id = $input['item_id'];
    $new_status = $input['status'];
    
    // Validate status
    $valid_statuses = ['damaged', 'under_repair', 'repaired'];
    if (!in_array($new_status, $valid_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit();
    }
    
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    
    try {
        // Get repair item details
        $stmt = $pdo->prepare("SELECT * FROM repair_items WHERE id = ?");
        $stmt->execute([$repair_item_id]);
        $repair_item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$repair_item) {
            throw new Exception('Repair item not found');
        }
        
        // Update repair status
        $stmt = $pdo->prepare("
            UPDATE repair_items 
            SET repair_status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$new_status, $repair_item_id]);
        
        // If marked as repaired, return item to main inventory
        if ($new_status === 'repaired') {
            // Check if item exists in main inventory
            $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
            $stmt->execute([$repair_item['item_id']]);
            $inventory_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inventory_item) {
                // Add quantity back to inventory - ONLY update available_quantity, NOT total quantity
                $stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET available_quantity = available_quantity + ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $repair_item['quantity'], 
                    $repair_item['item_id']
                ]);
                
                // When an item is marked as repaired, update any related borrowing requests
                // Find borrowing requests that include this item and are still active
                $stmt = $pdo->prepare("
                    SELECT id, student_id, approved_items 
                    FROM borrowing_requests 
                    WHERE current_status IN ('active', 'pending_return')
                    AND EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements(
                            CASE WHEN LEFT(TRIM(COALESCE(approved_items, '')), 1) = '['
                                THEN approved_items::jsonb ELSE '[]'::jsonb END
                        ) AS approved_item
                        WHERE approved_item->>'id' = CAST(? AS text)
                           OR approved_item->>'item_id' = CAST(? AS text)
                    )
                ");
                $stmt->execute([
                    $repair_item['item_id'], 
                    $repair_item['item_id']
                ]);
                $borrowing_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Update each matching borrowing request to 'returned' status
                foreach ($borrowing_requests as $request) {
                    $stmt = $pdo->prepare("
                        UPDATE borrowing_requests 
                        SET current_status = 'returned',
                            notes = CONCAT(COALESCE(notes, ''), 
                                          CASE WHEN notes IS NULL OR notes = '' THEN '' ELSE '; ' END,
                                          'Item returned after repair completion')
                        WHERE id = ?
                    ");
                    $stmt->execute([$request['id']]);
                }
            } else {
                // Create new inventory item if it doesn't exist
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (item_name, category, quantity, available_quantity, condition_status, status, created_at)
                    VALUES (?, ?, ?, ?, 'good', 'available', NOW())
                ");
                $stmt->execute([
                    $repair_item['item_name'],
                    $repair_item['category'],
                    $repair_item['quantity'],
                    $repair_item['quantity']
                ]);
            }
            
            // Remove from repair items
            $stmt = $pdo->prepare("DELETE FROM repair_items WHERE id = ?");
            $stmt->execute([$repair_item_id]);
        }
        
        $pdo->commit();
        
        $message = '';
        switch ($new_status) {
            case 'under_repair':
                $message = 'Item marked as under repair';
                break;
            case 'repaired':
                $borrowing_count = isset($borrowing_requests) ? count($borrowing_requests) : 0;
                if ($borrowing_count > 0) {
                    $message = 'Item marked as repaired and returned to inventory. ' . $borrowing_count . ' borrowing request(s) updated to returned status.';
                } else {
                    $message = 'Item marked as repaired and returned to inventory';
                }
                break;
            default:
                $message = 'Status updated successfully';
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error updating repair status: " . $e->getMessage());
    error_log("Error details - Item ID: " . ($repair_item_id ?? 'unknown') . ", Status: " . ($new_status ?? 'unknown'));
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update repair status: ' . $e->getMessage(),
        'details' => [
            'item_id' => $repair_item_id ?? null,
            'status' => $new_status ?? null,
            'error_message' => $e->getMessage(),
            'error_line' => $e->getLine()
        ]
    ]);
}
?>