<?php
// Archive Students Section
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
    $where_conditions = ["status = 'archived'", $campusFilter];
    $params = $campusParams;
    
    if (!empty($search)) {
        $where_conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR sr_code LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
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
    $count_sql = "SELECT COUNT(*) FROM student_artists $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_items = $count_stmt->fetchColumn();
    
    // Calculate pagination
    $total_pages = ceil($total_items / $items_per_page);
    
    // Get archived students
    $sql = "SELECT id, sr_code, first_name, middle_name, last_name, email, campus, cultural_group, 
            program, year_level, updated_at, created_at 
            FROM student_artists $where_clause 
            ORDER BY updated_at DESC 
            LIMIT $items_per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $archived_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($archived_students)) {
        echo '<div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p>No archived students found</p>
                <small>Students that have been archived will appear here</small>
            </div>';
    } else {
        foreach ($archived_students as $student) {
            $full_name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
            $archived_date = date('M d, Y', strtotime($student['updated_at']));
            
            echo '<div class="archive-card student">
                    <div class="archive-header">
                        <div class="archive-title">' . htmlspecialchars($full_name) . '</div>
                        <div class="archive-badge">Student</div>
                    </div>
                    <div class="archive-meta">
                        <strong>SR Code:</strong> ' . htmlspecialchars($student['sr_code']) . ' | 
                        <strong>Email:</strong> ' . htmlspecialchars($student['email']) . '<br>
                        <strong>Campus:</strong> ' . htmlspecialchars($student['campus']) . ' | 
                        <strong>Program:</strong> ' . htmlspecialchars($student['program']) . '<br>
                        <strong>Cultural Group:</strong> ' . htmlspecialchars($student['cultural_group'] ?: 'Not Assigned') . '<br>
                        <strong>Archived:</strong> ' . $archived_date . '
                    </div>
                    <div class="archive-actions">';
            
            if ($canManage) {
                echo '<button class="restore-btn" onclick="restoreItem(\'student\', ' . $student['id'] . ')">
                        Restore
                      </button>
                      <button class="delete-permanent-btn" onclick="deletePermament(\'student\', ' . $student['id'] . ')">
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
                        Showing ' . (empty($archived_students) ? 0 : $offset + 1) . ' to ' . min($offset + $items_per_page, $total_items) . ' of ' . $total_items . ' entries
                    </span>
                    <div class="pagination-controls">';
            
            if ($current_page > 1) {
                echo '<a href="?section=students&page=' . ($current_page - 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Previous</a>';
            }
            
            for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
                if ($i == $current_page) {
                    echo '<span class="pagination-number active">' . $i . '</span>';
                } else {
                    echo '<a href="?section=students&page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-number">' . $i . '</a>';
                }
            }
            
            if ($current_page < $total_pages) {
                echo '<a href="?section=students&page=' . ($current_page + 1) . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="pagination-btn">Next</a>';
            }
            
            echo '  </div>
                  </div>';
        }
    }
} catch (Exception $e) {
    echo '<div class="empty-state">
            <p>Error loading archived students</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
          </div>';
}
?>
