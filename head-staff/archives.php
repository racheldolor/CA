<?php
session_start();
require_once '../config/database.php';

// Authentication check - Only heads can access archives
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin'])) {
    header('Location: ../index.php');
    exit();
}

$pdo = getDBConnection();

// === RBAC: Get user's campus and determine access level ===
$user_role = $_SESSION['user_role'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_campus_raw = $_SESSION['user_campus'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'Unknown User';

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
$user_campus = $campus_name_map[$user_campus_raw] ?? $user_campus_raw;

// Display campus name
$display_campus = $user_campus;
$isDirector = ($user_role === 'director');

// Campus filtering logic:
// - Pablo Borbon head: see all campuses
// - Other campus head: see only their campus
$canViewAll = ($user_campus === 'Pablo Borbon');
$canManage = true; // All heads can manage
$campus_filter = isset($_GET['campus_filter']) ? trim($_GET['campus_filter']) : '';

// Build campus filter for SQL
if ($canViewAll && isset($_GET['campus_filter']) && !empty($_GET['campus_filter'])) {
    $selected_campus = $_GET['campus_filter'];
    if ($selected_campus === 'JPLPC Malvar') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($selected_campus === 'ARASOF Nasugbu') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } elseif ($selected_campus === 'Pablo Borbon') {
        $campusFilter = 'campus = ?';
        $campusParams = ['Pablo Borbon'];
    } else {
        $campusFilter = 'campus = ?';
        $campusParams = [$selected_campus];
    }
} elseif ($canViewAll) {
    $campusFilter = '1=1'; // No filter - see all
    $campusParams = [];
} else {
    // For non-Pablo Borbon users, check both short and full names
    if ($user_campus === 'JPLPC Malvar') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['JPLPC Malvar', 'Malvar'];
    } elseif ($user_campus === 'ARASOF Nasugbu') {
        $campusFilter = '(campus = ? OR campus = ?)';
        $campusParams = ['ARASOF Nasugbu', 'Nasugbu'];
    } else {
        $campusFilter = 'campus = ?';
        $campusParams = [$user_campus];
    }
}

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset = ($current_page - 1) * $items_per_page;

// Get active section (default to events)
$active_section = isset($_GET['section']) ? $_GET['section'] : 'events';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archives - Culture and Arts</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Header */
        .header {
            background: white;
            color: #dc2626;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #e0e0e0;
            width: 100%;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .header-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            color: #333;
        }
        
        .user-info span {
            white-space: nowrap;
        }

        .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* Main Container */
        .main-container {
            display: flex;
            min-height: calc(100vh - 70px);
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            max-width: 280px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            min-height: calc(100vh - 70px);
            position: fixed;
            top: 70px;
            left: 0;
            z-index: 50;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-greeting {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            position: relative;
            overflow: hidden;
        }

        .sidebar-greeting::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .sidebar-greeting::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .sidebar-greeting h3 {
            margin: 0 0 0.5rem 0;
            color: white;
            font-weight: 700;
            position: relative;
            z-index: 1;
            line-height: 1.2;
        }

        .greeting-hi {
            font-size: 1.8rem;
            font-weight: 400;
        }

        .greeting-name {
            font-size: 1.8rem;
        }

        .sidebar-greeting p {
            margin: 0 0 0 0.5rem;
            font-style: bold;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.95);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .nav-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.5rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: #f8f9fa;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        .nav-link.active {
            background: #fee2e2;
            color: #dc2626;
            border-left-color: #dc2626;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        /* Filter Section */
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            align-items: end;
        }

        .filter-group {
            flex: 1;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        /* Archive Cards */
        .archive-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #6c757d;
        }
        
        .archive-card.student {
            border-left-color: #dc2626;
        }
        
        .archive-card.event {
            border-left-color: #2196f3;
        }
        
        .archive-card.inventory {
            border-left-color: #ff9800;
        }
        
        .archive-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .archive-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .archive-badge {
            background: #f0f0f0;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            color: #666;
        }
        
        .archive-meta {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .archive-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .restore-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .restore-btn:hover {
            background: #218838;
        }
        
        .delete-permanent-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .delete-permanent-btn:hover {
            background: #bd2130;
        }
        
        .view-details-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .view-details-btn:hover {
            background: #5a6268;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            align-items: end;
        }
        
        .filter-group {
            flex: 1;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../assets/OCA Logo.png" alt="BatStateU Logo" class="logo">
            <h1 class="header-title">Culture and Arts - Dashboard</h1>
        </div>
        <div class="header-right">
            <?php 
            $first_name = explode(' ', $_SESSION['user_name'])[0];
            $role_display = strtoupper($user_role);
            ?>
            <button class="logout-btn" onclick="window.location.href='../logout.php'">Logout</button>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-greeting">
                <h3>
                    <span class="greeting-hi">Hi,</span>
                    <span class="greeting-name"><?= htmlspecialchars($first_name) ?></span>
                </h3>
                <p><?= htmlspecialchars($role_display) ?><?php if (!$isDirector): ?> - <?= htmlspecialchars($display_campus) ?><?php endif; ?></p>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="announcements.php" class="nav-link">
                            Announcements
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=student-profiles" class="nav-link">
                            Student Artist Profiles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=events-trainings" class="nav-link">
                            Events & Trainings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php?section=reports-analytics" class="nav-link">
                            Reports & Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="inventory.php" class="nav-link">
                            Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="archives.php" class="nav-link active">
                            Archives
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Archives Management</h1>
            </div>

            <!-- Archive Statistics -->
            <div class="stats-grid">
                <?php
                // Get archive counts
                try {
                    // Archived events
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM events WHERE status = 'archived' AND $campusFilter");
                    $stmt->execute($campusParams);
                    $archived_events = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    // Archived inventory (if table exists)
                    $tableExists = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'inventory'")->fetchColumn();
                    if ($tableExists) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE status = 'archived' AND $campusFilter");
                        $stmt->execute($campusParams);
                        $archived_inventory = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    } else {
                        $archived_inventory = 0;
                    }
                } catch (Exception $e) {
                    $archived_events = 0;
                    $archived_inventory = 0;
                }
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $archived_events ?></div>
                    <div class="stat-label">Archived Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $archived_inventory ?></div>
                    <div class="stat-label">Archived Inventory</div>
                </div>
            </div>

            <!-- Section Tabs -->
            <div style="border-bottom: 2px solid #e0e0e0; margin-bottom: 2rem;">
                <div style="display: flex; gap: 2rem;">
                    <a href="?section=events" 
                       class="<?= $active_section === 'events' ? 'active' : '' ?>"
                       style="padding: 1rem 0; text-decoration: none; color: <?= $active_section === 'events' ? '#dc2626' : '#666' ?>; border-bottom: 3px solid <?= $active_section === 'events' ? '#dc2626' : 'transparent' ?>; font-weight: 600;">
                        Archived Events
                    </a>
                    <a href="?section=inventory" 
                       class="<?= $active_section === 'inventory' ? 'active' : '' ?>"
                       style="padding: 1rem 0; text-decoration: none; color: <?= $active_section === 'inventory' ? '#dc2626' : '#666' ?>; border-bottom: 3px solid <?= $active_section === 'inventory' ? '#dc2626' : 'transparent' ?>; font-weight: 600;">
                        Archived Inventory
                    </a>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Search archives..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <?php if ($canViewAll): ?>
                <div class="filter-group" style="max-width: 200px;">
                    <label>Campus</label>
                    <select id="campusFilter">
                        <option value="">All Campuses</option>
                        <option value="Pablo Borbon">Pablo Borbon</option>
                        <option value="Alangilan">Alangilan</option>
                        <option value="Lipa">Lipa</option>
                        <option value="ARASOF Nasugbu">ARASOF Nasugbu</option>
                        <option value="JPLPC Malvar">JPLPC Malvar</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group" style="max-width: 200px;">
                    <label>Date Range</label>
                    <select id="dateFilter">
                        <option value="">All Time</option>
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="365">Last year</option>
                    </select>
                </div>
                <button onclick="applyFilters()" style="background: #dc2626; color: white; border: none; padding: 0.5rem 1.5rem; border-radius: 4px; cursor: pointer; height: fit-content;">
                    Apply Filters
                </button>
            </div>

            <!-- Content Area -->
            <div id="archiveContent">
                <?php
                // Display content based on active section
                if ($active_section === 'events') {
                    include 'archive_events_section.php';
                } elseif ($active_section === 'inventory') {
                    include 'archive_inventory_section.php';
                }
                ?>
            </div>
        </main>
    </div>

    <script>
        const userCampus = '<?= htmlspecialchars($user_campus ?? '', ENT_QUOTES) ?>';
        const canViewAll = <?= $canViewAll ? 'true' : 'false' ?>;

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const campus = document.getElementById('campusFilter')?.value || '';
            const dateRange = document.getElementById('dateFilter').value;
            
            const params = new URLSearchParams(window.location.search);
            if (search) params.set('search', search);
            else params.delete('search');
            
            if (campus) params.set('campus_filter', campus);
            else params.delete('campus_filter');
            
            if (dateRange) params.set('days', dateRange);
            else params.delete('days');
            
            window.location.href = '?' + params.toString();
        }

        function restoreItem(type, id) {
            if (confirm('Are you sure you want to restore this item?')) {
                fetch('restore_archive.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        type: type,
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Item restored successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to restore item'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while restoring the item');
                });
            }
        }

        function deletePermament(type, id) {
            if (confirm('WARNING: This will permanently delete this item. This action cannot be undone. Are you sure?')) {
                fetch('delete_archive_permanent.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        type: type,
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Item permanently deleted!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete item'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the item');
                });
            }
        }

        // Enable Enter key for search
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    </script>
</body>
</html>
