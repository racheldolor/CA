<?php
// Prevent any output before JSON
ob_start();

// Set content type to JSON and turn off error display
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(0);

// Start session
session_start();

// Check if user is logged in and is admin (head)
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get user's campus for the inventory item
$user_campus = $_SESSION['user_campus'] ?? null;

// Database connection using centralized config
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
} catch(PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get JSON input
$json_input = file_get_contents('php://input');
$input = json_decode($json_input, true);

if (!$input) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid input data. Received: ' . $json_input]);
    exit;
}

// Validate required fields
$required_fields = ['name', 'category', 'condition', 'quantity'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => ucfirst($field) . ' is required']);
        exit;
    }
}

// Validate quantity
$quantity = intval($input['quantity']);
if ($quantity < 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Quantity cannot be negative']);
    exit;
}

// Validate category values
if (!in_array($input['category'], ['costume', 'equipment'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid category. Must be costume or equipment.']);
    exit;
}

// Validate condition values
if (!in_array($input['condition'], ['good', 'worn-out', 'bad'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid condition. Must be good, worn-out, or bad.']);
    exit;
}

try {
    // Check if editing existing item
    if (isset($input['id']) && !empty($input['id'])) {
        // Update existing item
        // Get current quantities and status to preserve borrowed amount and valid status
        $current_stmt = $pdo->prepare("SELECT quantity, available_quantity, status FROM inventory WHERE id = :id");
        $current_stmt->execute([':id' => $input['id']]);
        $current = $current_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current) {
            $old_total = intval($current['quantity']);
            $old_available = intval($current['available_quantity']);
            $currently_borrowed = $old_total - $old_available; // Calculate current borrowed amount
            
            // New available = new total - currently borrowed (preserve borrowed amount)
            $new_available = $quantity - $currently_borrowed;
            $new_available = max(0, $new_available); // Ensure it's not negative
            
            // Preserve current status unless we need to update it based on quantity
            // Only auto-set status to 'available' if quantity > 0 and current status is not maintenance/archived/retired
            $current_status = $current['status'];
            if ($quantity > 0 && !in_array($current_status, ['maintenance', 'archived', 'retired', 'borrowed'])) {
                $auto_status = 'available';
            } elseif ($quantity == 0 && $current_status == 'available') {
                // Keep as available even with 0 quantity (allows name updates)
                $auto_status = 'available';
            } else {
                // Preserve existing status
                $auto_status = $current_status;
            }
        } else {
            $new_available = $quantity; // If no record found, set available = total
            $auto_status = 'available'; // Default to available
        }
        
        $sql = "UPDATE inventory 
                SET item_name = :item_name, 
                    category = :category, 
                    quantity = :quantity,
                    available_quantity = :available_quantity,
                    condition_status = :condition_status, 
                    status = :status, 
                    description = :description,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':item_name' => trim($input['name']),
            ':category' => $input['category'],
            ':quantity' => $quantity,
            ':available_quantity' => $new_available,
            ':condition_status' => $input['condition'],
            ':status' => $auto_status,
            ':description' => trim($input['description'] ?? ''),
            ':id' => $input['id']
        ]);

        if ($result) {
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Item updated successfully!',
                'item_id' => $input['id']
            ]);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to update item']);
        }
    } else {
        // Insert new item with campus
        // For new items, always set status to 'available' (valid enum value)
        // Set available_quantity equal to quantity initially (no items borrowed yet)
        $sql = "INSERT INTO inventory (item_name, category, quantity, available_quantity, condition_status, status, description, campus) 
                VALUES (:item_name, :category, :quantity, :available_quantity, :condition_status, :status, :description, :campus)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':item_name' => trim($input['name']),
            ':category' => $input['category'],
            ':quantity' => $quantity,
            ':available_quantity' => $quantity, // Initially, all items are available
            ':condition_status' => $input['condition'],
            ':status' => 'available', // Always use valid status value
            ':description' => trim($input['description'] ?? ''),
            ':campus' => $user_campus
        ]);

        if ($result) {
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Item added successfully!',
                'item_id' => $pdo->lastInsertId()
            ]);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to insert item']);
        }
    }

} catch(PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()]);
}
?>