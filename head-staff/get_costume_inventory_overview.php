<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus = $_SESSION['user_campus'] ?? null;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

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
$normalized_campus = $campus_name_map[$user_campus] ?? $user_campus;

// Build campus filter - always exclude archived items
$campusFilter = "WHERE status != 'archived'";
$campusParams = [];

if (!$canViewAll && $normalized_campus) {
    // For Malvar and Nasugbu, check both short and full names
    if ($normalized_campus === 'JPLPC Malvar') {
        $campusFilter .= ' AND (campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($normalized_campus === 'ARASOF Nasugbu') {
        $campusFilter .= ' AND (campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $campusFilter .= ' AND campus = ?';
        $campusParams = [$normalized_campus];
    }
}

header('Content-Type: application/json');

try {
    $pdo = getDBConnection();
    
    // Drop old costume_inventory table if it exists to avoid confusion
    try {
        $pdo->exec("DROP TABLE IF EXISTS costume_inventory");
    } catch (Exception $e) {
        // Ignore errors
    }
    
    // Check if inventory table exists
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SELECT 1 FROM inventory LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        // Table doesn't exist, return empty data
        $tableExists = false;
    }
    
    if ($tableExists) {
        // Get inventory statistics using the real inventory table with campus filtering
        // Calculate available items based on actual availability (quantity - borrowed)
        // First get basic stats
        $basicStatsSql = "
            SELECT 
                COUNT(*) as total_items,
                COUNT(CASE WHEN quantity = 0 OR status = 'unavailable' THEN 1 END) as unavailable_items,
                COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_items,
                COUNT(CASE WHEN condition_status IN ('poor', 'damaged') THEN 1 END) as damaged_items,
                COUNT(CASE WHEN condition_status = 'excellent' THEN 1 END) as excellent_items
            FROM inventory
            " . $campusFilter . "
        ";
        $statsStmt = $pdo->prepare($basicStatsSql);
        $statsStmt->execute($campusParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Add damaged items from repair_items table (items that are damaged or under repair)
        $repairItemsCountSql = "SELECT COUNT(*) as repair_items_count FROM repair_items WHERE repair_status IN ('damaged', 'under_repair')";
        $repairStmt = $pdo->prepare($repairItemsCountSql);
        $repairStmt->execute();
        $repairItemsCount = $repairStmt->fetch(PDO::FETCH_ASSOC)['repair_items_count'];
        
        // Add repair items to damaged items count
        $stats['damaged_items'] = (int)$stats['damaged_items'] + (int)$repairItemsCount;
        
        // Calculate borrowed count from active borrowing requests
        $borrowedCountSql = "
            SELECT COUNT(DISTINCT borrowed.item_id) as borrowed_count
            FROM (
                SELECT DISTINCT (borrowed_item->>'id')::integer as item_id
                FROM borrowing_requests br
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                        THEN br.approved_items::jsonb ELSE '[]'::jsonb END
                ) AS borrowed_item
                WHERE br.status = 'approved'
                AND br.current_status IN ('active', 'pending_return')
                AND jsonb_exists(borrowed_item, 'id')
            ) borrowed
            INNER JOIN inventory ON inventory.id = borrowed.item_id
            WHERE inventory.status != 'archived'
        ";
        
        if (!$canViewAll && $normalized_campus) {
            if ($normalized_campus === 'JPLPC Malvar') {
                $borrowedCountSql .= " AND (inventory.campus = 'JPLPC Malvar' OR inventory.campus = 'Malvar')";
            } elseif ($normalized_campus === 'ARASOF Nasugbu') {
                $borrowedCountSql .= " AND (inventory.campus = 'ARASOF Nasugbu' OR inventory.campus = 'Nasugbu')";
            } else {
                $borrowedCountSql .= " AND inventory.campus = '" . $normalized_campus . "'";
            }
        }
        
        $borrowedStmt = $pdo->prepare($borrowedCountSql);
        $borrowedStmt->execute();
        $stats['borrowed_items'] = (int)$borrowedStmt->fetch(PDO::FETCH_ASSOC)['borrowed_count'];
        
        // Calculate available items: items that have quantity > 0 and are not marked as unavailable/maintenance
        // and are not fully borrowed out (this counts ITEMS, not quantities)
        $availableCountSql = "
            SELECT COUNT(*) as available_count
            FROM inventory
            WHERE inventory.status != 'archived'
            AND inventory.status NOT IN ('unavailable', 'maintenance') 
            AND inventory.quantity > 0
            AND inventory.id NOT IN (
                SELECT DISTINCT item_id_cast FROM (
                    SELECT 
                        (borrowed_item->>'id')::integer as item_id_cast,
                        SUM(COALESCE((borrowed_item->>'quantity')::integer, 1)) as total_borrowed
                    FROM borrowing_requests br
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN LEFT(TRIM(COALESCE(br.approved_items, '')), 1) = '['
                            THEN br.approved_items::jsonb ELSE '[]'::jsonb END
                    ) AS borrowed_item
                    WHERE br.status = 'approved'
                    AND br.current_status IN ('active', 'pending_return')
                    AND jsonb_exists(borrowed_item, 'id')
                    GROUP BY (borrowed_item->>'id')::integer
                ) borrowed_summary
                INNER JOIN inventory inv2 ON inv2.id = borrowed_summary.item_id_cast
                WHERE borrowed_summary.total_borrowed >= inv2.quantity
            )
        ";
        
        if (!$canViewAll && $normalized_campus) {
            if ($normalized_campus === 'JPLPC Malvar') {
                $availableCountSql .= " AND (inventory.campus = 'JPLPC Malvar' OR inventory.campus = 'Malvar')";
            } elseif ($normalized_campus === 'ARASOF Nasugbu') {
                $availableCountSql .= " AND (inventory.campus = 'ARASOF Nasugbu' OR inventory.campus = 'Nasugbu')";
            } else {
                $availableCountSql .= " AND inventory.campus = '" . $normalized_campus . "'";
            }
        }
        
        $availableStmt = $pdo->prepare($availableCountSql);
        $availableStmt->execute();
        $stats['available_items'] = (int)$availableStmt->fetch(PDO::FETCH_ASSOC)['available_count'];
        
        // Debug: Log the actual query results
        error_log("Costume Inventory Debug - Campus: " . ($normalized_campus ?? 'all') . " - Stats: " . json_encode($stats));
        
        // Get items by category with campus filtering (exclude archived)
        // Only count items with quantity > 0 as available
        $categoryFilter = $campusFilter . " AND category IS NOT NULL";
        $groupSql = "
            SELECT 
                category,
                COUNT(*) as item_count,
                COUNT(CASE WHEN status = 'available' AND quantity > 0 THEN 1 END) as available_count
            FROM inventory
            " . $categoryFilter . "
            GROUP BY category
            ORDER BY item_count DESC
            LIMIT 6
        ";
        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute($campusParams);
        $groupBreakdown = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get items needing attention (poor condition or maintenance) with campus filtering (exclude archived)
        $attentionFilter = $campusFilter . " AND (condition_status IN ('poor', 'damaged') OR status = 'maintenance')";
        $attentionSql = "
            SELECT 
                item_name,
                category,
                condition_status,
                status,
                updated_at as last_used
            FROM inventory
            " . $attentionFilter . "
            ORDER BY 
                CASE condition_status 
                    WHEN 'damaged' THEN 1 
                    WHEN 'poor' THEN 2 
                    ELSE 3 
                END,
                updated_at DESC
            LIMIT 5
        ";
        $attentionStmt = $pdo->prepare($attentionSql);
        $attentionStmt->execute($campusParams);
        $itemsNeedingAttention = $attentionStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate utilization rate (borrowed / total available)
        $availableForBorrow = $stats['available_items'] + $stats['borrowed_items'];
        $utilizationRate = $availableForBorrow > 0 ? round(($stats['borrowed_items'] / $availableForBorrow) * 100) : 0;
    } else {
        // No inventory table exists, return empty data
        $stats = [
            'total_items' => 0,
            'available_items' => 0,
            'borrowed_items' => 0,
            'maintenance_items' => 0,
            'damaged_items' => 0,
            'excellent_items' => 0
        ];
        $groupBreakdown = [];
        $itemsNeedingAttention = [];
        $utilizationRate = 0;
    }
    
    $response = [
        'success' => true,
        'statistics' => array_merge($stats, ['utilization_rate' => $utilizationRate]),
        'group_breakdown' => $groupBreakdown,
        'items_needing_attention' => $itemsNeedingAttention
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch costume inventory data: ' . $e->getMessage()
    ]);
}
?>