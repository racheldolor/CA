<?php
// Archive Inventory Section
require_once __DIR__ . '/../config/database.php';

$pdo = $pdo ?? getDBConnection();
$campusFilter = $campusFilter ?? '1=1';
$campusParams = $campusParams ?? [];
$items_per_page = $items_per_page ?? 10;
$current_page = $current_page ?? 1;
$offset = $offset ?? (($current_page - 1) * $items_per_page);
$search = $search ?? '';
$canManage = $canManage ?? false;

try {
    // Check if inventory table exists
    $tableExists = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'inventory'")->fetchColumn();
    
    if (!$tableExists) {
        echo '<div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p>Inventory system not yet set up</p>
                <small>Archived inventory items will appear here once the system is configured</small>
            </div>';
    } else {
        $where_conditions = ["status = 'archived'", $campusFilter];
        $params = $campusParams;
        
        if (!empty($search)) {
            $where_conditions[] = "(item_name LIKE ? OR category LIKE ? OR description LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        // Date filter
        if (isset($_GET['days']) && is_numeric($_GET['days'])) {
            $days = intval($_GET['days']);
            $where_conditions[] = "updated_at >= CURRENT_TIMESTAMP - (? * INTERVAL '1 day')";
            $params[] = $days;
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM inventory $where_clause";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_items = $count_stmt->fetchColumn();
        
        // Calculate pagination
        $total_pages = ceil($total_items / $items_per_page);
        
        // Get archived inventory items
        $sql = "SELECT id, item_name, category, description, quantity, condition_status, 
                campus, updated_at, created_at 
                FROM inventory $where_clause 
                ORDER BY updated_at DESC 
                LIMIT $items_per_page OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $archived_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($archived_items)) {
            echo '<div class="empty-state">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p>No archived inventory items found</p>
                    <small>Items that have been archived will appear here</small>
                </div>';
        } else {
            foreach ($archived_items as $item) {
                $archived_date = date('M d, Y', strtotime($item['updated_at']));
                
                echo '<div class="archive-card inventory">
                        <div class="archive-header">
                            <div class="archive-title">' . htmlspecialchars($item['item_name']) . '</div>
                            <div class="archive-badge">' . htmlspecialchars($item['category']) . '</div>
                        </div>
                        <div class="archive-meta">
                            <strong>Quantity:</strong> ' . htmlspecialchars($item['quantity']) . ' | 
                            <strong>Condition:</strong> ' . htmlspecialchars($item['condition_status']) . '<br>
                            <strong>Campus:</strong> ' . htmlspecialchars($item['campus']) . '<br>';
                
                if (!empty($item['description'])) {
                    echo '<strong>Description:</strong> ' . htmlspecialchars(substr($item['description'], 0, 100)) . (strlen($item['description']) > 100 ? '...' : '') . '<br>';
                }
                
                echo '      <strong>Archived:</strong> ' . $archived_date . '
                        </div>
                        <div class="archive-actions">';
                
                if ($canManage) {
                    echo '<button class="restore-btn" onclick="restoreItem(\'inventory\', ' . $item['id'] . ')">
                            Restore
                          </button>
                          <button class="delete-permanent-btn" onclick="deletePermament(\'inventory\', ' . $item['id'] . ')">
                            Delete Permanently
                          </button>';
                }
                
                echo '  </div>
                      </div>';
            }
            
            // Pagination
            if ($total_pages > 1) {
                echo '<div class="pagination" style="margin-top: 2rem;">
                        <span class="pagination-info">
                            Showing ' . (empty($archived_items) ? 0 : $offset + 1) . ' to ' . min($offset + $items_per_page, $total_items) . ' of ' . $total_items . ' entries
                        </span>
                        <div class="pagination-controls">';
                
                if ($current_page > 1) {
                    echo '<a href="?section=inventory&page=' . ($current_page - 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Previous</a>';
                }
                
                for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
                    if ($i == $current_page) {
                        echo '<span class="pagination-number active">' . $i . '</span>';
                    } else {
                        echo '<a href="?section=inventory&page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-number">' . $i . '</a>';
                    }
                }
                
                if ($current_page < $total_pages) {
                    echo '<a href="?section=inventory&page=' . ($current_page + 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Next</a>';
                }
                
                echo '  </div>
                      </div>';
            }
        }
    }
} catch (Exception $e) {
    echo '<div class="empty-state">
            <p>Error loading archived inventory</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
          </div>';
}
?>
