<?php
// Archive Events Section
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
    // Build WHERE conditions - only add campusFilter if it's not '1=1' (which means show all)
    $where_conditions = ["status = 'archived'"];
    $params = [];
    
    // Add campus filter only if it's not '1=1' (show all)
    if ($campusFilter !== '1=1') {
        $where_conditions[] = $campusFilter;
        $params = array_merge($params, $campusParams);
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(title LIKE ? OR description LIKE ? OR location LIKE ? OR category LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
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
    $count_sql = "SELECT COUNT(*) FROM events $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_items = $count_stmt->fetchColumn();
    
    // Calculate pagination
    $total_pages = ceil($total_items / $items_per_page);
    
    // Get archived events
    $sql = "SELECT id, title, description, start_date, end_date, location, campus, category, 
            cultural_groups, updated_at, created_at 
            FROM events $where_clause 
            ORDER BY updated_at DESC 
            LIMIT $items_per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $archived_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($archived_events)) {
        echo '<div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <p>No archived events found</p>
                <small>Events that have been archived will appear here</small>
            </div>';
    } else {
        foreach ($archived_events as $event) {
            $start_date = date('M d, Y', strtotime($event['start_date']));
            $end_date = date('M d, Y', strtotime($event['end_date']));
            $archived_date = date('M d, Y', strtotime($event['updated_at']));
            
            echo '<div class="archive-card event">
                    <div class="archive-header">
                        <div class="archive-title">' . htmlspecialchars($event['title']) . '</div>
                        <div class="archive-badge">' . htmlspecialchars($event['category']) . '</div>
                    </div>
                    <div class="archive-meta">
                        <strong>Date:</strong> ' . $start_date . ' - ' . $end_date . '<br>
                        <strong>Location:</strong> ' . htmlspecialchars($event['location']) . '<br>
                        <strong>Campus:</strong> ' . htmlspecialchars($event['campus']) . '<br>
                        <strong>Cultural Groups:</strong> ' . htmlspecialchars($event['cultural_groups'] ?: 'All') . '<br>
                        <strong>Description:</strong> ' . htmlspecialchars(substr($event['description'], 0, 150)) . (strlen($event['description']) > 150 ? '...' : '') . '<br>
                        <strong>Archived:</strong> ' . $archived_date . '
                    </div>
                    <div class="archive-actions">';
            
            if ($canManage) {
                echo '<button class="restore-btn" onclick="restoreItem(\'event\', ' . $event['id'] . ')">
                        Restore
                      </button>
                      <button class="delete-permanent-btn" onclick="deletePermament(\'event\', ' . $event['id'] . ')">
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
                        Showing ' . (empty($archived_events) ? 0 : $offset + 1) . ' to ' . min($offset + $items_per_page, $total_items) . ' of ' . $total_items . ' entries
                    </span>
                    <div class="pagination-controls">';
            
            if ($current_page > 1) {
                echo '<a href="?section=events&page=' . ($current_page - 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Previous</a>';
            }
            
            for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
                if ($i == $current_page) {
                    echo '<span class="pagination-number active">' . $i . '</span>';
                } else {
                    echo '<a href="?section=events&page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-number">' . $i . '</a>';
                }
            }
            
            if ($current_page < $total_pages) {
                echo '<a href="?section=events&page=' . ($current_page + 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Next</a>';
            }
            
            echo '  </div>
                  </div>';
        }
    }
} catch (Exception $e) {
    echo '<div class="empty-state">
            <p>Error loading archived events</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
          </div>';
}
?>
